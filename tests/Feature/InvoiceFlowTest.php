<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CreditNote;
use App\Models\InvoiceDraft;
use App\Services\CompanyApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceFlowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $companyToken;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('facturaya.ai.driver', 'demo');
        Storage::fake('local');

        [$this->company, $this->companyToken] = $this->createCompany('20111111111', 'Empresa Uno S.A.C.');
    }

    public function test_company_token_is_required(): void
    {
        $this->getJson('/api/company')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Falta el token de la empresa.');

        $this->withToken($this->companyToken)
            ->getJson('/api/company')
            ->assertOk()
            ->assertJsonPath('data.ruc', $this->company->ruc);
    }

    public function test_it_imports_a_document_and_calculates_included_igv(): void
    {
        $response = $this->post('/api/invoice-drafts/import', [
            ...$this->customerData(),
            'tax_mode' => 'included',
            'file' => $this->fakePdf(),
        ], $this->companyHeaders());

        $response
            ->assertCreated()
            ->assertJsonPath('data.company.id', $this->company->id)
            ->assertJsonPath('data.status', 'review_required')
            ->assertJsonPath('data.source.extractor', 'demo')
            ->assertJsonPath('data.totals.subtotal', '1652.54')
            ->assertJsonPath('data.totals.igv', '297.46')
            ->assertJsonPath('data.totals.total', '1950.00')
            ->assertJsonCount(2, 'data.items');

        Storage::disk('local')->assertExists(InvoiceDraft::firstOrFail()->source_path);
    }

    public function test_it_imports_products_written_in_natural_language(): void
    {
        $sourceText = 'Vendí 2 laptops a S/ 2,500 cada una y 3 licencias a S/ 120. Los precios incluyen IGV.';

        $response = $this->post('/api/invoice-drafts/import', [
            ...$this->customerData(),
            'tax_mode' => 'included',
            'products_text' => $sourceText,
        ], $this->companyHeaders());

        $response
            ->assertCreated()
            ->assertJsonPath('data.source.type', 'text')
            ->assertJsonPath('data.source.name', 'productos-escritos.txt')
            ->assertJsonPath('data.source.mime_type', 'text/plain')
            ->assertJsonPath('data.source.extractor', 'demo')
            ->assertJsonPath('data.status', 'review_required');

        $draft = InvoiceDraft::firstOrFail();
        Storage::disk('local')->assertExists($draft->source_path);
        $this->assertSame($sourceText, Storage::disk('local')->get($draft->source_path));
    }

    public function test_natural_language_input_requires_a_product_description(): void
    {
        $this->post('/api/invoice-drafts/import', [
            ...$this->customerData(),
            'tax_mode' => 'included',
            'products_text' => '',
        ], $this->companyHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['products_text', 'file']);
    }

    public function test_it_combines_written_products_and_a_file_in_one_draft(): void
    {
        $response = $this->post('/api/invoice-drafts/import', [
            ...$this->customerData(),
            'tax_mode' => 'included',
            'products_text' => 'Agrega tres meses de soporte a S/ 250 y corrige cualquier duplicado del archivo.',
            'file' => $this->fakePdf(),
        ], $this->companyHeaders());

        $response
            ->assertCreated()
            ->assertJsonPath('data.source.type', 'mixed')
            ->assertJsonPath('data.source.name', 'Texto + cotizacion.pdf')
            ->assertJsonPath('data.status', 'review_required');

        $draft = InvoiceDraft::firstOrFail();
        Storage::disk('local')->assertExists($draft->source_path);

        $manifest = json_decode(Storage::disk('local')->get($draft->source_path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('mixed', $manifest['type']);
        Storage::disk('local')->assertExists($manifest['text_path']);
        Storage::disk('local')->assertExists($manifest['file_path']);
    }

    public function test_it_allows_review_then_issues_with_the_company_fake_gateway(): void
    {
        $created = $this->post('/api/invoice-drafts/import', [
            ...$this->customerData(),
            'tax_mode' => 'excluded',
            'file' => $this->fakePdf(),
        ], $this->companyHeaders())->assertCreated()->json('data');

        $updated = $this->withToken($this->companyToken)->putJson('/api/invoice-drafts/'.$created['id'], [
            ...$this->customerData(),
            'tax_mode' => 'excluded',
            'currency' => 'PEN',
            'items' => [[
                'description' => 'Consultoría especializada',
                'quantity' => 2,
                'unit_price' => 100,
                'confidence' => 1,
                'source_page' => 1,
            ]],
        ]);

        $updated
            ->assertOk()
            ->assertJsonPath('data.totals.subtotal', '200.00')
            ->assertJsonPath('data.totals.igv', '36.00')
            ->assertJsonPath('data.totals.total', '236.00');

        $issued = $this->withToken($this->companyToken)
            ->postJson('/api/invoice-drafts/'.$created['id'].'/issue');

        $issued
            ->assertCreated()
            ->assertJsonPath('data.number', 'F001-00000001')
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.sunat.code', '0');

        $invoice = InvoiceDraft::findOrFail($created['id'])->invoice;
        Storage::disk('local')->assertExists($invoice->xml_path);
        Storage::disk('local')->assertExists($invoice->cdr_path);

        $this->withToken($this->companyToken)
            ->postJson('/api/invoice-drafts/'.$created['id'].'/issue')
            ->assertOk()
            ->assertJsonPath('data.number', 'F001-00000001');
    }

    public function test_companies_are_isolated_and_have_independent_correlatives(): void
    {
        [$secondCompany, $secondToken] = $this->createCompany('20222222222', 'Empresa Dos S.A.C.');

        $firstDraft = $this->createDraftForToken($this->companyToken);
        $secondDraft = $this->createDraftForToken($secondToken);

        $this->withToken($secondToken)
            ->getJson('/api/invoice-drafts/'.$firstDraft)
            ->assertNotFound();

        $this->withToken($this->companyToken)
            ->getJson('/api/invoice-drafts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company.id', $this->company->id);

        $firstInvoice = $this->withToken($this->companyToken)
            ->postJson('/api/invoice-drafts/'.$firstDraft.'/issue')
            ->assertCreated();
        $secondInvoice = $this->withToken($secondToken)
            ->postJson('/api/invoice-drafts/'.$secondDraft.'/issue')
            ->assertCreated();

        $firstInvoice->assertJsonPath('data.number', 'F001-00000001');
        $secondInvoice
            ->assertJsonPath('data.company_id', $secondCompany->id)
            ->assertJsonPath('data.number', 'F001-00000001');
    }

    public function test_it_issues_a_full_credit_note_against_an_accepted_invoice(): void
    {
        $draftId = $this->createDraftForToken($this->companyToken);
        $invoice = $this->withToken($this->companyToken)
            ->postJson('/api/invoice-drafts/'.$draftId.'/issue')
            ->assertCreated()
            ->json('data');

        $response = $this->withToken($this->companyToken)
            ->postJson('/api/invoices/'.$invoice['id'].'/credit-notes', [
                'issue_date' => '2026-08-15',
                'reason_code' => '01',
                'reason_description' => 'Operación anulada a solicitud del cliente.',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.number', 'FC01-00000001')
            ->assertJsonPath('data.affected_document', 'F001-00000001')
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.reason.code', '01')
            ->assertJsonPath('data.totals.total', '1950.00')
            ->assertJsonCount(2, 'data.items');

        $note = CreditNote::firstOrFail();
        Storage::disk('local')->assertExists($note->xml_path);
        Storage::disk('local')->assertExists($note->cdr_path);
        $this->assertStringContainsString(
            '<AffectedDocument>F001-00000001</AffectedDocument>',
            Storage::disk('local')->get($note->xml_path),
        );

        $this->withToken($this->companyToken)
            ->getJson('/api/invoices/'.$invoice['id'].'/credit-notes')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_partial_credit_note_cannot_exceed_original_item_or_cross_companies(): void
    {
        $draftId = $this->createDraftForToken($this->companyToken);
        $invoice = $this->withToken($this->companyToken)
            ->postJson('/api/invoice-drafts/'.$draftId.'/issue')
            ->assertCreated()
            ->json('data');
        $draft = $this->withToken($this->companyToken)
            ->getJson('/api/invoice-drafts/'.$draftId)
            ->assertOk()
            ->json('data');
        $firstItem = $draft['items'][0];

        $this->withToken($this->companyToken)
            ->postJson('/api/invoices/'.$invoice['id'].'/credit-notes', [
                'issue_date' => '2026-08-15',
                'reason_code' => '07',
                'reason_description' => 'Devolución parcial de una unidad.',
                'items' => [[
                    'invoice_draft_item_id' => $firstItem['id'],
                    'quantity' => 1,
                    'unit_price' => $firstItem['unit_price'],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'accepted');

        $this->withToken($this->companyToken)
            ->postJson('/api/invoices/'.$invoice['id'].'/credit-notes', [
                'issue_date' => '2026-08-15',
                'reason_code' => '07',
                'reason_description' => 'Intento de devolución excesiva.',
                'items' => [[
                    'invoice_draft_item_id' => $firstItem['id'],
                    'quantity' => $firstItem['quantity'],
                    'unit_price' => $firstItem['unit_price'],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        [, $secondToken] = $this->createCompany('20333333333', 'Empresa Ajena S.A.C.');
        $this->withToken($secondToken)
            ->postJson('/api/invoices/'.$invoice['id'].'/credit-notes', [
                'issue_date' => '2026-08-15',
                'reason_code' => '01',
                'reason_description' => 'No autorizada.',
            ])
            ->assertNotFound();
    }

    public function test_credit_note_requires_an_accepted_invoice(): void
    {
        $draftId = $this->createDraftForToken($this->companyToken);
        $draft = InvoiceDraft::findOrFail($draftId);
        $invoice = $draft->invoice()->create([
            'company_id' => $this->company->id,
            'series' => 'F001',
            'correlative' => 99,
            'status' => 'rejected',
        ]);

        $this->withToken($this->companyToken)
            ->postJson('/api/invoices/'.$invoice->id.'/credit-notes', [
                'issue_date' => '2026-08-15',
                'reason_code' => '01',
                'reason_description' => 'No debe emitirse.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invoice');
    }

    private function createDraftForToken(string $token): string
    {
        return $this->post('/api/invoice-drafts/import', [
            ...$this->customerData(),
            'tax_mode' => 'included',
            'file' => $this->fakePdf(),
        ], $this->companyHeaders($token))->assertCreated()->json('data.id');
    }

    /**
     * @return array{Company, string}
     */
    private function createCompany(string $ruc, string $legalName): array
    {
        $company = Company::create([
            'ruc' => $ruc,
            'legal_name' => $legalName,
            'trade_name' => $legalName,
            'ubigeo' => '150101',
            'department' => 'LIMA',
            'province' => 'LIMA',
            'district' => 'LIMA',
            'address' => 'Av. Prueba 123',
            'sunat_driver' => 'fake',
            'sunat_environment' => 'beta',
            'default_series' => 'F001',
            'active' => true,
        ]);
        $issued = app(CompanyApiTokenService::class)->create($company, 'Pruebas');

        return [$company, $issued['plain_text']];
    }

    private function companyHeaders(?string $token = null): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.($token ?? $this->companyToken),
        ];
    }

    private function customerData(): array
    {
        return [
            'customer_ruc' => '20123456789',
            'customer_name' => 'Comercial Andina S.A.C.',
            'issue_date' => '2026-08-15',
        ];
    }

    private function fakePdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'cotizacion.pdf',
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF",
        );
    }
}
