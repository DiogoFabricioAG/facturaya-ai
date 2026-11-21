<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceDraft;
use App\Models\InvoiceDraftItem;
use App\Services\CreditNotePdfService;
use App\Services\InvoicePdfService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class FiscalPdfIssuerFromXmlTest extends TestCase
{
    private function ublXml(string $root, string $legalName, ?string $tradeName = null): string
    {
        $partyName = $tradeName !== null
            ? "<cac:PartyName><cbc:Name>{$tradeName}</cbc:Name></cac:PartyName>"
            : '';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<{$root} xmlns="urn:oasis:names:specification:ubl:schema:xsd:{$root}-2"
         xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
         xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:ID>F001-00000123</cbc:ID>
  <cbc:IssueDate>2026-11-20</cbc:IssueDate>
  <cac:AccountingSupplierParty>
    <cac:Party>
      <cac:PartyIdentification><cbc:ID schemeID="6">20123456789</cbc:ID></cac:PartyIdentification>
      {$partyName}
      <cac:PartyLegalEntity>
        <cbc:RegistrationName>{$legalName}</cbc:RegistrationName>
      </cac:PartyLegalEntity>
    </cac:Party>
  </cac:AccountingSupplierParty>
</{$root}>
XML;
    }

    private function fakeCompany(): Company
    {
        $company = new Company;
        $company->ruc = '20123456789';
        $company->legal_name = 'EMPRESA ACTUAL S.A.C.';
        $company->trade_name = 'MARCA ACTUAL';
        $company->address = 'AV. EJEMPLO 123';
        $company->district = 'LIMA';
        $company->province = 'LIMA';
        $company->department = 'LIMA';

        return $company;
    }

    private function fakeInvoice(Company $company, ?string $xmlPath): Invoice
    {
        $item = new InvoiceDraftItem;
        $item->description = 'Servicio de prueba';
        $item->quantity = 1;
        $item->entered_unit_price = 100;
        $item->unit_price_with_igv = 118;
        $item->line_total = 118;

        $draft = new InvoiceDraft;
        $draft->document_type = '01';
        $draft->customer_document_type = '6';
        $draft->customer_ruc = '20987654321';
        $draft->customer_name = 'CLIENTE EJEMPLO S.A.C.';
        $draft->currency = 'PEN';
        $draft->payment_method = 'contado';
        $draft->subtotal = 100;
        $draft->igv = 18;
        $draft->total = 118;
        $draft->setRelation('items', new Collection([$item]));

        $invoice = new Invoice;
        $invoice->number = 'F001-00000123';
        $invoice->document_type = '01';
        $invoice->status = 'accepted';
        $invoice->xml_path = $xmlPath;
        $invoice->setRelation('company', $company);
        $invoice->setRelation('draft', $draft);

        return $invoice;
    }

    private function fakeCreditNote(Company $company, Invoice $invoice, ?string $xmlPath): CreditNote
    {
        $item = new CreditNoteItem;
        $item->description = 'Anulacion de servicio';
        $item->quantity = 1;
        $item->entered_unit_price = 100;
        $item->unit_price_with_igv = 118;
        $item->line_total = 118;

        $note = new CreditNote;
        $note->number = 'FC01-00000005';
        $note->series = 'FC01';
        $note->status = 'accepted';
        $note->reason_code = '01';
        $note->reason_description = 'Anulacion de la operacion';
        $note->currency = 'PEN';
        $note->subtotal = 100;
        $note->igv = 18;
        $note->total = 118;
        $note->xml_path = $xmlPath;
        $note->setRelation('company', $company);
        $note->setRelation('invoice', $invoice);
        $note->setRelation('items', new Collection([$item]));

        return $note;
    }

    public function test_pdf_uses_registration_name_and_trade_name_from_ubl_xml(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('xml/inv-xml.xml', $this->ublXml('Invoice', 'EMPRESA HISTORICA XML S.A.C.', 'NOMBRE COMERCIAL XML'));
        Storage::disk('local')->put('xml/nc-xml.xml', $this->ublXml('CreditNote', 'EMPRESA HISTORICA XML S.A.C.', 'NOMBRE COMERCIAL XML'));

        $company = $this->fakeCompany();
        $invoice = $this->fakeInvoice($company, 'xml/inv-xml.xml');
        $note = $this->fakeCreditNote($company, $invoice, 'xml/nc-xml.xml');

        $invoicePdf = (new InvoicePdfService)->render($invoice);
        $notePdf = (new CreditNotePdfService)->render($note);

        self::assertStringContainsString('EMPRESA HISTORICA XML S.A.C.', $invoicePdf);
        self::assertStringContainsString('NOMBRE COMERCIAL XML', $invoicePdf);
        self::assertStringNotContainsString('EMPRESA ACTUAL S.A.C.', $invoicePdf);
        self::assertStringNotContainsString('MARCA ACTUAL', $invoicePdf);

        self::assertStringContainsString('EMPRESA HISTORICA XML S.A.C.', $notePdf);
        self::assertStringContainsString('NOMBRE COMERCIAL XML', $notePdf);
        self::assertStringNotContainsString('EMPRESA ACTUAL S.A.C.', $notePdf);
    }

    public function test_pdf_falls_back_to_company_profile_when_xml_is_missing(): void
    {
        Storage::fake('local');
        $company = $this->fakeCompany();
        $invoice = $this->fakeInvoice($company, null);
        $note = $this->fakeCreditNote($company, $invoice, null);

        $invoicePdf = (new InvoicePdfService)->render($invoice);
        $notePdf = (new CreditNotePdfService)->render($note);

        self::assertStringContainsString('EMPRESA ACTUAL S.A.C.', $invoicePdf);
        self::assertStringContainsString('MARCA ACTUAL', $invoicePdf);
        self::assertStringContainsString('EMPRESA ACTUAL S.A.C.', $notePdf);
        self::assertStringContainsString('MARCA ACTUAL', $notePdf);
    }

    public function test_pdf_omits_trade_name_when_xml_has_no_party_name(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('xml/inv-no-trade.xml', $this->ublXml('Invoice', 'EMPRESA SIN COMERCIAL S.A.C.', null));
        Storage::disk('local')->put('xml/nc-no-trade.xml', $this->ublXml('CreditNote', 'EMPRESA SIN COMERCIAL S.A.C.', null));

        $company = $this->fakeCompany();
        $invoice = $this->fakeInvoice($company, 'xml/inv-no-trade.xml');
        $note = $this->fakeCreditNote($company, $invoice, 'xml/nc-no-trade.xml');

        $invoicePdf = (new InvoicePdfService)->render($invoice);
        $notePdf = (new CreditNotePdfService)->render($note);

        self::assertStringContainsString('EMPRESA SIN COMERCIAL S.A.C.', $invoicePdf);
        self::assertStringNotContainsString('MARCA ACTUAL', $invoicePdf);
        self::assertStringContainsString('EMPRESA SIN COMERCIAL S.A.C.', $notePdf);
        self::assertStringNotContainsString('MARCA ACTUAL', $notePdf);
        self::assertStringNotContainsString('NOMBRE COMERCIAL:', $notePdf);
    }

    public function test_pdf_falls_back_to_company_profile_when_xml_is_malformed(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('xml/malformed.xml', '<Invoice><broken');

        $company = $this->fakeCompany();
        $invoice = $this->fakeInvoice($company, 'xml/malformed.xml');
        $note = $this->fakeCreditNote($company, $invoice, 'xml/malformed.xml');

        $invoicePdf = (new InvoicePdfService)->render($invoice);
        $notePdf = (new CreditNotePdfService)->render($note);

        self::assertStringStartsWith('%PDF-1.4', $invoicePdf);
        self::assertStringContainsString('EMPRESA ACTUAL S.A.C.', $invoicePdf);
        self::assertStringContainsString('MARCA ACTUAL', $invoicePdf);

        self::assertStringStartsWith('%PDF-1.4', $notePdf);
        self::assertStringContainsString('EMPRESA ACTUAL S.A.C.', $notePdf);
        self::assertStringContainsString('MARCA ACTUAL', $notePdf);
    }
}
