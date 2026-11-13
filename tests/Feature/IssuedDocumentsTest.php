<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceDraft;
use App\Services\CompanyApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssuedDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->company('20111111111', 'production');
        $this->withToken(app(CompanyApiTokenService::class)->create($this->company, 'Pruebas')['plain_text']);
    }

    public function test_lists_invoices_receipts_and_credit_notes_of_the_current_environment(): void
    {
        $factura = $this->invoice($this->company, 1, ['issue_date' => '2026-11-05', 'total' => '118.00', 'subtotal' => '100.00', 'igv' => '18.00']);
        $this->invoice($this->company, 2, ['issue_date' => '2026-11-06', 'document_type' => '03', 'customer_document_type' => '1',
            'customer_ruc' => '12345678', 'total' => '59.00', 'subtotal' => '50.00', 'igv' => '9.00'], ['document_type' => '03', 'series' => 'B001']);
        $this->invoice($this->company, 3, ['issue_date' => '2026-11-07'], ['sunat_environment' => 'beta']);
        CreditNote::create(['company_id' => $this->company->id, 'invoice_id' => $factura->id, 'sunat_environment' => 'production',
            'series' => 'FC01', 'correlative' => 1, 'issue_date' => '2026-11-08', 'reason_code' => '01',
            'reason_description' => 'Anulación', 'currency' => 'PEN', 'subtotal' => '100.00', 'igv' => '18.00', 'total' => '118.00', 'status' => 'accepted']);
        $other = $this->company('20999999999', 'production');
        $this->invoice($other, 1, ['issue_date' => '2026-11-09']);

        $response = $this->getJson('/api/issued-documents')->assertOk();

        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('data.0.number', 'FC01-00000001');
        $response->assertJsonPath('data.0.related_number', 'F001-00000001');
        $response->assertJsonPath('data.1.document_type', '03');
        $response->assertJsonPath('data.2.total', '118.00');
        $response->assertJsonPath('data.2.files.pdf', true);
        $this->assertArrayNotHasKey('created_at', $response->json('data.0'));
    }

    public function test_filters_by_type_and_dates(): void
    {
        $this->invoice($this->company, 1, ['issue_date' => '2026-10-31']);
        $this->invoice($this->company, 2, ['issue_date' => '2026-11-02']);
        $this->invoice($this->company, 3, ['issue_date' => '2026-11-03', 'document_type' => '03'], ['document_type' => '03', 'series' => 'B001']);

        $this->getJson('/api/issued-documents?from=2026-11-01&to=2026-11-30&type=01')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.number', 'F001-00000002');
        $this->getJson('/api/issued-documents?type=99')->assertUnprocessable();
    }

    public function test_monthly_summary_adds_accepted_sales_and_subtracts_credit_notes(): void
    {
        $a = $this->invoice($this->company, 1, ['issue_date' => '2026-11-05', 'subtotal' => '100.00', 'igv' => '18.00', 'total' => '118.00']);
        $this->invoice($this->company, 2, ['issue_date' => '2026-11-20', 'subtotal' => '50.00', 'igv' => '9.00', 'total' => '59.00']);
        $this->invoice($this->company, 3, ['issue_date' => '2026-11-21', 'total' => '999.00'], ['status' => 'rejected']);
        CreditNote::create(['company_id' => $this->company->id, 'invoice_id' => $a->id, 'sunat_environment' => 'production',
            'series' => 'FC01', 'correlative' => 1, 'issue_date' => '2026-11-25', 'reason_code' => '07',
            'reason_description' => 'Devolución', 'currency' => 'PEN', 'subtotal' => '10.00', 'igv' => '1.80', 'total' => '11.80', 'status' => 'accepted']);

        $response = $this->getJson('/api/reports/monthly?year=2026')->assertOk();

        $response->assertJsonCount(12, 'data');
        $response->assertJsonPath('data.10.month', '2026-11');
        $response->assertJsonPath('data.10.documents', 2);
        $response->assertJsonPath('data.10.credit_notes', 1);
        $response->assertJsonPath('data.10.total', '165.20');
        $response->assertJsonPath('data.10.igv', '25.20');
        $response->assertJsonPath('data.0.total', '0.00');
    }

    public function test_requires_the_company_token(): void
    {
        $this->withToken('token-falso')->getJson('/api/issued-documents')->assertUnauthorized();
        $this->withToken('token-falso')->getJson('/api/reports/monthly?year=2026')->assertUnauthorized();
    }

    private function company(string $ruc, string $environment): Company
    {
        return Company::create(['ruc' => $ruc, 'legal_name' => "Empresa $ruc", 'trade_name' => "Empresa $ruc",
            'ubigeo' => '150101', 'department' => 'LIMA', 'province' => 'LIMA', 'district' => 'LIMA', 'address' => 'Prueba 123',
            'sunat_driver' => 'fake', 'sunat_environment' => $environment, 'default_series' => 'F001', 'active' => true]);
    }

    private function invoice(Company $company, int $correlative, array $draft = [], array $invoice = []): Invoice
    {
        $stored = InvoiceDraft::create(['company_id' => $company->id, 'document_type' => '01', 'customer_document_type' => '6',
            'customer_ruc' => '20444444444', 'customer_name' => 'Cliente de prueba', 'issue_date' => '2026-11-05', 'tax_mode' => 'included',
            'currency' => 'PEN', 'status' => 'issued', 'ai_driver' => 'demo', 'source_path' => 'fixture.txt',
            'original_name' => 'fixture.txt', 'mime_type' => 'text/plain', ...$draft]);

        return Invoice::create(['company_id' => $company->id, 'invoice_draft_id' => $stored->id, 'document_type' => '01',
            'sunat_environment' => 'production', 'series' => 'F001', 'correlative' => $correlative, 'status' => 'accepted', ...$invoice]);
    }
}
