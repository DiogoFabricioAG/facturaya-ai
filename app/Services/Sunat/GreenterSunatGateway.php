<?php

namespace App\Services\Sunat;

use App\Contracts\SunatGateway;
use App\Models\Company as StoredCompany;
use App\Models\CreditNote as StoredCreditNote;
use App\Models\Invoice as StoredInvoice;
use App\Models\InvoiceDraft;
use App\Services\AmountInWords;
use App\Services\CompanyCertificateStore;
use DateTime;
use DateTimeZone;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\Note;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Ws\Services\SunatEndpoints;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class GreenterSunatGateway implements SunatGateway
{
    public function __construct(
        private readonly AmountInWords $amountInWords,
        private readonly CompanyCertificateStore $certificates,
    ) {}

    public function issue(InvoiceDraft $draft, StoredInvoice $invoice): array
    {
        $draft->loadMissing(['items', 'company']);
        $company = $draft->company;
        $see = $this->makeSee($company);
        $greenterInvoice = $this->makeInvoice($draft, $invoice, $company);
        $result = $see->send($greenterInvoice);
        $directory = 'companies/'.$company->id.'/invoices/'.$invoice->id;
        $xmlPath = $directory.'/'.$greenterInvoice->getName().'.xml';

        Storage::disk('local')->put($xmlPath, (string) $see->getFactory()->getLastXml());

        if ($result === null || ! $result->isSuccess()) {
            $error = $result?->getError();

            $this->logRejectedTransport($see, $company, $invoice->number, $error?->getCode(), $error?->getMessage());

            return [
                'status' => 'error',
                'code' => (string) ($error?->getCode() ?? 'CONNECTION_ERROR'),
                'message' => (string) ($error?->getMessage() ?? 'SUNAT no devolvió una respuesta.'),
                'notes' => [],
                'xml_path' => $xmlPath,
                'cdr_path' => null,
            ];
        }

        $cdr = $result->getCdrResponse();
        $code = (int) $cdr->getCode();
        $cdrPath = $directory.'/R-'.$greenterInvoice->getName().'.zip';
        $cdrZip = $result->getCdrZip();

        if ($cdrZip === null) {
            throw new RuntimeException('SUNAT aceptó la operación, pero Greenter no devolvió el CDR.');
        }

        Storage::disk('local')->put($cdrPath, $cdrZip);

        return [
            'status' => $code === 0 ? 'accepted' : ($code >= 2000 && $code <= 3999 ? 'rejected' : 'error'),
            'code' => (string) $code,
            'message' => (string) $cdr->getDescription(),
            'notes' => array_values($cdr->getNotes() ?? []),
            'xml_path' => $xmlPath,
            'cdr_path' => $cdrPath,
        ];
    }

    public function issueCreditNote(StoredCreditNote $creditNote): array
    {
        $creditNote->loadMissing(['items', 'invoice.draft', 'company']);
        $company = $creditNote->company;
        $see = $this->makeSee($company);
        $greenterNote = $this->makeCreditNote($creditNote, $company);
        $result = $see->send($greenterNote);
        $directory = 'companies/'.$company->id.'/credit-notes/'.$creditNote->id;
        $xmlPath = $directory.'/'.$greenterNote->getName().'.xml';

        Storage::disk('local')->put($xmlPath, (string) $see->getFactory()->getLastXml());

        if ($result === null || ! $result->isSuccess()) {
            $error = $result?->getError();

            $this->logRejectedTransport($see, $company, $creditNote->number, $error?->getCode(), $error?->getMessage());

            return [
                'status' => 'error',
                'code' => (string) ($error?->getCode() ?? 'CONNECTION_ERROR'),
                'message' => (string) ($error?->getMessage() ?? 'SUNAT no devolvió una respuesta.'),
                'notes' => [],
                'xml_path' => $xmlPath,
                'cdr_path' => null,
            ];
        }

        $cdr = $result->getCdrResponse();
        $code = (int) $cdr->getCode();
        $cdrPath = $directory.'/R-'.$greenterNote->getName().'.zip';
        $cdrZip = $result->getCdrZip();

        if ($cdrZip === null) {
            throw new RuntimeException('SUNAT aceptó la nota de crédito, pero Greenter no devolvió el CDR.');
        }

        Storage::disk('local')->put($cdrPath, $cdrZip);

        return [
            'status' => $code === 0 ? 'accepted' : ($code >= 2000 && $code <= 3999 ? 'rejected' : 'error'),
            'code' => (string) $code,
            'message' => (string) $cdr->getDescription(),
            'notes' => array_values($cdr->getNotes() ?? []),
            'xml_path' => $xmlPath,
            'cdr_path' => $cdrPath,
        ];
    }

    private function makeSee(StoredCompany $company): DiagnosticSee
    {
        if (! $company->hasSunatCredentials()) {
            throw new RuntimeException('La empresa no tiene certificado, usuario SOL y contraseña SOL completos.');
        }

        $see = new DiagnosticSee;
        $see->setCertificate($this->certificates->read($company));
        $see->setService(
            $company->sunat_environment === 'production'
                ? SunatEndpoints::FE_PRODUCCION
                : SunatEndpoints::FE_BETA,
        );
        $see->setClaveSOL(
            $company->ruc,
            (string) $company->sol_user,
            (string) $company->sol_password,
        );

        return $see;
    }

    private function logRejectedTransport(
        DiagnosticSee $see,
        StoredCompany $company,
        string $documentNumber,
        string|int|null $code,
        ?string $message,
    ): void {
        Log::warning('SUNAT rejected the electronic document transport', [
            'company_id' => $company->id,
            'document_number' => $documentNumber,
            'environment' => $company->sunat_environment,
            'sunat_code' => (string) ($code ?? 'CONNECTION_ERROR'),
            'sunat_message' => (string) ($message ?? 'SUNAT no devolvió una respuesta.'),
            'transport' => $see->transportDiagnostics(),
        ]);
    }

    private function makeInvoice(InvoiceDraft $draft, StoredInvoice $storedInvoice, StoredCompany $storedCompany): Invoice
    {
        $address = (new Address)
            ->setUbigueo($storedCompany->ubigeo)
            ->setDepartamento($storedCompany->department)
            ->setProvincia($storedCompany->province)
            ->setDistrito($storedCompany->district)
            ->setUrbanizacion('-')
            ->setDireccion($storedCompany->address)
            ->setCodLocal('0000');

        $company = (new Company)
            ->setRuc($storedCompany->ruc)
            ->setRazonSocial($storedCompany->legal_name)
            ->setNombreComercial($storedCompany->trade_name ?: $storedCompany->legal_name)
            ->setAddress($address);

        $documentType = (string) ($storedInvoice->document_type ?: $draft->document_type ?: '01');
        $client = (new Client)
            ->setTipoDoc((string) ($draft->customer_document_type ?: ($documentType === '03' ? '1' : '6')))
            ->setNumDoc($draft->customer_ruc)
            ->setRznSocial($draft->customer_name);

        $details = $draft->items->map(fn ($item) => (new SaleDetail)
            ->setCodProducto('ITEM-'.str_pad((string) $item->position, 3, '0', STR_PAD_LEFT))
            ->setUnidad('NIU')
            ->setCantidad((float) $item->quantity)
            ->setMtoValorUnitario((float) $item->unit_value)
            ->setDescripcion($item->description)
            ->setMtoBaseIgv((float) $item->line_base)
            ->setPorcentajeIgv(18.00)
            ->setIgv((float) $item->igv)
            ->setTipAfeIgv('10')
            ->setTotalImpuestos((float) $item->igv)
            ->setMtoValorVenta((float) $item->line_base)
            ->setMtoPrecioUnitario((float) $item->unit_price_with_igv))
            ->all();

        return (new Invoice)
            ->setUblVersion('2.1')
            ->setTipoOperacion('0101')
            ->setTipoDoc($documentType)
            ->setSerie($storedInvoice->series)
            ->setCorrelativo((string) $storedInvoice->correlative)
            ->setFechaEmision(new DateTime($draft->issue_date->format('Y-m-d').' 12:00:00', new DateTimeZone('America/Lima')))
            ->setFormaPago(new FormaPagoContado)
            ->setTipoMoneda($draft->currency)
            ->setCompany($company)
            ->setClient($client)
            ->setMtoOperGravadas((float) $draft->subtotal)
            ->setMtoIGV((float) $draft->igv)
            ->setTotalImpuestos((float) $draft->igv)
            ->setValorVenta((float) $draft->subtotal)
            ->setSubTotal((float) $draft->total)
            ->setMtoImpVenta((float) $draft->total)
            ->setDetails($details)
            ->setLegends([
                (new Legend)
                    ->setCode('1000')
                    ->setValue($this->amountInWords->currency($draft->total, $draft->currency)),
            ]);
    }

    private function makeCreditNote(StoredCreditNote $storedNote, StoredCompany $storedCompany): Note
    {
        $draft = $storedNote->invoice->draft;
        $address = (new Address)
            ->setUbigueo($storedCompany->ubigeo)
            ->setDepartamento($storedCompany->department)
            ->setProvincia($storedCompany->province)
            ->setDistrito($storedCompany->district)
            ->setUrbanizacion('-')
            ->setDireccion($storedCompany->address)
            ->setCodLocal('0000');

        $company = (new Company)
            ->setRuc($storedCompany->ruc)
            ->setRazonSocial($storedCompany->legal_name)
            ->setNombreComercial($storedCompany->trade_name ?: $storedCompany->legal_name)
            ->setAddress($address);

        $client = (new Client)
            ->setTipoDoc((string) ($draft->customer_document_type ?: ($storedNote->invoice->document_type === '03' ? '1' : '6')))
            ->setNumDoc($draft->customer_ruc)
            ->setRznSocial($draft->customer_name);

        $details = $storedNote->items->map(fn ($item) => (new SaleDetail)
            ->setCodProducto('ITEM-'.str_pad((string) $item->position, 3, '0', STR_PAD_LEFT))
            ->setUnidad('NIU')
            ->setCantidad((float) $item->quantity)
            ->setMtoValorUnitario((float) $item->unit_value)
            ->setDescripcion($item->description)
            ->setMtoBaseIgv((float) $item->line_base)
            ->setPorcentajeIgv(18.00)
            ->setIgv((float) $item->igv)
            ->setTipAfeIgv('10')
            ->setTotalImpuestos((float) $item->igv)
            ->setMtoValorVenta((float) $item->line_base)
            ->setMtoPrecioUnitario((float) $item->unit_price_with_igv))
            ->all();

        return (new Note)
            ->setUblVersion('2.1')
            ->setTipoDoc('07')
            ->setSerie($storedNote->series)
            ->setCorrelativo((string) $storedNote->correlative)
            ->setFechaEmision(new DateTime($storedNote->issue_date->format('Y-m-d').' 12:00:00', new DateTimeZone('America/Lima')))
            ->setTipDocAfectado((string) ($storedNote->invoice->document_type ?: '01'))
            ->setNumDocfectado($storedNote->invoice->number)
            ->setCodMotivo($storedNote->reason_code)
            ->setDesMotivo($storedNote->reason_description)
            ->setTipoMoneda($storedNote->currency)
            ->setCompany($company)
            ->setClient($client)
            ->setMtoOperGravadas((float) $storedNote->subtotal)
            ->setMtoIGV((float) $storedNote->igv)
            ->setTotalImpuestos((float) $storedNote->igv)
            ->setValorVenta((float) $storedNote->subtotal)
            ->setSubTotal((float) $storedNote->total)
            ->setMtoImpVenta((float) $storedNote->total)
            ->setDetails($details)
            ->setLegends([
                (new Legend)
                    ->setCode('1000')
                    ->setValue($this->amountInWords->currency($storedNote->total, $storedNote->currency)),
            ]);
    }
}
