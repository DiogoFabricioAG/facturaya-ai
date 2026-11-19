<?php

namespace Tests\Feature;

use App\Contracts\DocumentExtractor;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceDraft;
use App\Models\InvoiceSequence;
use App\Services\CompanyApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AnonymousBoletaFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('local');
        $company = Company::create([
            'ruc' => '20111111111',
            'legal_name' => 'Empresa de prueba',
            'ubigeo' => '150101',
            'department' => 'LIMA',
            'province' => 'LIMA',
            'district' => 'LIMA',
            'address' => 'Av. Prueba 123',
            'sunat_driver' => 'fake',
            'sunat_environment' => 'beta',
            'default_series' => 'F001',
            'default_boleta_series' => 'B001',
            'active' => true,
        ]);
        $this->withToken(app(CompanyApiTokenService::class)->create($company, 'Pruebas')['plain_text']);
        $this->mock(DocumentExtractor::class, function ($mock): void {
            $mock->shouldReceive('extract')->andReturn([
                'currency' => 'PEN',
                'items' => $this->items(125),
                'warnings' => [],
            ]);
        });
    }

    public static function anonymousTypes(): array
    {
        return [
            'string zero' => [['customer_document_type' => '0']],
            'numeric zero' => [['customer_document_type' => 0]],
            'omitted type' => [[]],
        ];
    }

    #[DataProvider('anonymousTypes')]
    public function test_import_review_and_fake_issue_of_125_soles(array $customer): void
    {
        $draft = $this->postJson('/api/invoice-drafts/import', [
            ...$this->payload(), ...$customer,
        ])->assertCreated()
            ->assertJsonPath('data.customer.document_type', '0')
            ->assertJsonPath('data.customer.ruc', null)
            ->assertJsonPath('data.customer.name', null)
            ->assertJsonPath('data.customer.is_anonymous', true)
            ->assertJsonPath('data.totals.total', '125.00')
            ->json('data');

        $this->putJson('/api/invoice-drafts/'.$draft['id'], [
            ...$this->payload(), ...$customer,
            'currency' => 'PEN',
            'items' => $this->items(125),
        ])->assertOk()->assertJsonPath('data.customer.is_anonymous', true);

        $issued = $this->postJson('/api/invoice-drafts/'.$draft['id'].'/issue')
            ->assertCreated()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.number', 'B001-00000001')
            ->json('data');

        $invoice = Invoice::findOrFail($issued['id']);
        $this->assertStringContainsString('<CustomerDocument type="0">-</CustomerDocument>', Storage::disk('local')->get($invoice->xml_path));
        $this->get('/api/invoices/'.$invoice->id.'/files/pdf')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson('/api/invoice-drafts/'.$draft['id'].'/issue')->assertOk();
        $this->assertDatabaseCount('invoices', 1);
        Http::assertNothingSent();
    }

    public function test_anonymous_boleta_preserves_an_optional_customer_name_everywhere_except_the_fiscal_xml(): void
    {
        $draft = $this->postJson('/api/invoice-drafts/import', [
            ...$this->payload(),
            'customer_document_type' => '0',
            'customer_name' => '  Patricia M  ',
        ])->assertCreated()
            ->assertJsonPath('data.customer.document_type', '0')
            ->assertJsonPath('data.customer.ruc', null)
            ->assertJsonPath('data.customer.name', 'Patricia M')
            ->assertJsonPath('data.customer.is_anonymous', true)
            ->json('data');

        $this->assertDatabaseHas('invoice_drafts', [
            'id' => $draft['id'],
            'customer_document_type' => '0',
            'customer_ruc' => null,
            'customer_name' => 'Patricia M',
        ]);

        $this->putJson('/api/invoice-drafts/'.$draft['id'], [
            ...$this->payload(),
            'customer_document_type' => '0',
            'customer_name' => '  Patricia M  ',
            'currency' => 'PEN',
            'items' => $this->items(125),
        ])->assertOk()
            ->assertJsonPath('data.customer.name', 'Patricia M')
            ->assertJsonPath('data.customer.is_anonymous', true);

        $issued = $this->postJson('/api/invoice-drafts/'.$draft['id'].'/issue')
            ->assertCreated()
            ->assertJsonPath('data.status', 'accepted')
            ->json('data');
        $invoice = Invoice::findOrFail($issued['id']);
        $xml = Storage::disk('local')->get($invoice->xml_path);
        $this->assertStringContainsString('<CustomerDocument type="0">-</CustomerDocument>', $xml);
        $this->assertStringContainsString('<CustomerName>CLIENTE VARIOS</CustomerName>', $xml);
        $this->assertStringNotContainsString('Patricia M', $xml);

        $pdf = $this->get('/api/invoices/'.$invoice->id.'/files/pdf')->assertOk();
        $this->assertStringContainsString('Patricia M', $pdf->getContent());
    }

    public function test_anonymous_boleta_rejects_a_customer_document(): void
    {
        $this->postJson('/api/invoice-drafts/import', [
            ...$this->payload(),
            'customer_document_type' => '0',
            'customer_ruc' => '20123456789',
            'customer_name' => 'Patricia M',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('customer_ruc');

        $this->assertDatabaseCount('invoice_drafts', 0);
    }

    public function test_anonymous_type_remains_invalid_for_facturas(): void
    {
        $this->postJson('/api/invoice-drafts/import', [
            ...$this->payload(),
            'document_type' => '01',
            'customer_document_type' => '0',
        ])->assertUnprocessable()->assertJsonValidationErrors('customer_document_type');

        $this->assertDatabaseCount('invoice_drafts', 0);
    }

    public function test_identified_customer_still_requires_number_and_name(): void
    {
        $this->postJson('/api/invoice-drafts/import', [
            ...$this->payload(),
            'customer_document_type' => '1',
        ])->assertUnprocessable()->assertJsonValidationErrors(['customer_ruc', 'customer_name']);
    }

    public function test_anonymous_boleta_over_configured_limit_cannot_consume_a_correlative(): void
    {
        $id = $this->postJson('/api/invoice-drafts/import', [
            ...$this->payload(),
            'customer_name' => 'Patricia M',
        ])->assertCreated()
            ->assertJsonPath('data.customer.name', 'Patricia M')
            ->json('data.id');
        $this->putJson('/api/invoice-drafts/'.$id, [
            ...$this->payload(),
            'customer_name' => 'Patricia M',
            'currency' => 'PEN',
            'items' => $this->items(700.01),
        ])->assertOk()
            ->assertJsonPath('data.customer.name', 'Patricia M')
            ->assertJsonPath('data.totals.total', '700.01');
        $this->postJson('/api/invoice-drafts/'.$id.'/issue')->assertUnprocessable();
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, InvoiceSequence::count());
        $this->assertSame('review_required', InvoiceDraft::findOrFail($id)->status);
    }

    public function test_anonymous_non_pen_boleta_keeps_the_name_but_cannot_be_issued(): void
    {
        $id = $this->postJson('/api/invoice-drafts/import', [
            ...$this->payload(),
            'customer_name' => 'Patricia M',
        ])->assertCreated()->json('data.id');

        $this->putJson('/api/invoice-drafts/'.$id, [
            ...$this->payload(),
            'customer_name' => 'Patricia M',
            'currency' => 'USD',
            'items' => $this->items(125),
        ])->assertOk()
            ->assertJsonPath('data.customer.name', 'Patricia M')
            ->assertJsonPath('data.currency', 'USD');

        $this->postJson('/api/invoice-drafts/'.$id.'/issue')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Las boletas en moneda distinta a PEN deben incluir la identificación del cliente.');
        $this->assertDatabaseCount('invoices', 0);
    }

    private function payload(): array
    {
        return [
            'document_type' => '03',
            'issue_date' => '2026-09-12',
            'tax_mode' => 'included',
            'products_text' => 'Un servicio por S/ 125, incluido IGV.',
        ];
    }

    private function items(float $price): array
    {
        return [['description' => 'Servicio de prueba', 'quantity' => 1, 'unit_price' => $price]];
    }
}
