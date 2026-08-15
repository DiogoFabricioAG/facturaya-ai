<?php

namespace App\Services\Sunat;

use App\Contracts\SunatGateway;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceDraft;
use Illuminate\Support\Facades\Storage;

final class FakeSunatGateway implements SunatGateway
{
    public function issue(InvoiceDraft $draft, Invoice $invoice): array
    {
        $draft->loadMissing('company');
        $name = $draft->company->ruc.'-01-'.$invoice->series.'-'.$invoice->correlative;
        $directory = 'companies/'.$draft->company_id.'/invoices/'.$invoice->id;
        $xmlPath = $directory.'/'.$name.'.xml';
        $cdrPath = $directory.'/R-'.$name.'.json';

        Storage::disk('local')->put($xmlPath, $this->fakeXml($draft, $invoice));
        Storage::disk('local')->put($cdrPath, json_encode([
            'code' => '0',
            'description' => 'MODO DEMOSTRACIÓN: factura aceptada localmente.',
            'notes' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return [
            'status' => 'accepted',
            'code' => '0',
            'message' => 'Modo demostración: factura aceptada localmente.',
            'notes' => ['No fue enviada a SUNAT porque esta empresa usa el modo fake.'],
            'xml_path' => $xmlPath,
            'cdr_path' => $cdrPath,
        ];
    }

    public function issueCreditNote(CreditNote $creditNote): array
    {
        $creditNote->loadMissing(['company', 'invoice', 'items']);
        $name = $creditNote->company->ruc.'-07-'.$creditNote->series.'-'.$creditNote->correlative;
        $directory = 'companies/'.$creditNote->company_id.'/credit-notes/'.$creditNote->id;
        $xmlPath = $directory.'/'.$name.'.xml';
        $cdrPath = $directory.'/R-'.$name.'.json';

        Storage::disk('local')->put($xmlPath, $this->fakeCreditNoteXml($creditNote));
        Storage::disk('local')->put($cdrPath, json_encode([
            'code' => '0',
            'description' => 'MODO DEMOSTRACIÓN: nota de crédito aceptada localmente.',
            'notes' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return [
            'status' => 'accepted',
            'code' => '0',
            'message' => 'Modo demostración: nota de crédito aceptada localmente.',
            'notes' => ['No fue enviada a SUNAT porque esta empresa usa el modo fake.'],
            'xml_path' => $xmlPath,
            'cdr_path' => $cdrPath,
        ];
    }

    private function fakeXml(InvoiceDraft $draft, Invoice $invoice): string
    {
        $customer = htmlspecialchars($draft->customer_name, ENT_XML1);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Invoice demo="true">
  <ID>{$invoice->series}-{$invoice->correlative}</ID>
  <CustomerRuc>{$draft->customer_ruc}</CustomerRuc>
  <CustomerName>{$customer}</CustomerName>
  <TaxableAmount>{$draft->subtotal}</TaxableAmount>
  <TaxAmount>{$draft->igv}</TaxAmount>
  <PayableAmount>{$draft->total}</PayableAmount>
</Invoice>
XML;
    }

    private function fakeCreditNoteXml(CreditNote $creditNote): string
    {
        $reason = htmlspecialchars($creditNote->reason_description, ENT_XML1);
        $lines = $creditNote->items->map(function ($item): string {
            $description = htmlspecialchars($item->description, ENT_XML1);

            return "  <CreditNoteLine quantity=\"{$item->quantity}\" total=\"{$item->line_total}\">{$description}</CreditNoteLine>";
        })->implode("\n");

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<CreditNote demo="true">
  <ID>{$creditNote->series}-{$creditNote->correlative}</ID>
  <AffectedDocument>{$creditNote->invoice->number}</AffectedDocument>
  <Reason code="{$creditNote->reason_code}">{$reason}</Reason>
  <TaxableAmount>{$creditNote->subtotal}</TaxableAmount>
  <TaxAmount>{$creditNote->igv}</TaxAmount>
  <PayableAmount>{$creditNote->total}</PayableAmount>
{$lines}
</CreditNote>
XML;
    }
}
