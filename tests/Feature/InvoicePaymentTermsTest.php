<?php

namespace Tests\Feature;

use App\Contracts\DocumentExtractor;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceDraft;
use App\Models\InvoiceSequence;
use App\Services\CompanyApiTokenService;
use App\Services\Sunat\GreenterSunatGateway;
use DOMDocument;
use DOMXPath;
use Greenter\Xml\Builder\InvoiceBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class InvoicePaymentTermsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        $company = Company::create([
            'ruc' => '20111111111', 'legal_name' => 'Empresa ficticia', 'ubigeo' => '150101',
            'department' => 'LIMA', 'province' => 'LIMA', 'district' => 'LIMA', 'address' => 'Prueba 123',
            'sunat_driver' => 'fake', 'sunat_environment' => 'beta', 'default_series' => 'F001', 'active' => true,
        ]);
        $this->withToken(app(CompanyApiTokenService::class)->create($company, 'Pruebas')['plain_text']);
        $this->mock(DocumentExtractor::class, function ($mock): void {
            $mock->shouldReceive('extract')->andReturn([
                'currency' => 'PEN', 'items' => [['description' => 'Servicio ficticio', 'quantity' => '1', 'unit_price' => '12630.00']], 'warnings' => [],
            ]);
        });
    }

    private function payload(): array
    {
        return [
            'document_type' => '01', 'customer_document_type' => '6', 'customer_ruc' => '20222222222',
            'customer_name' => 'Cliente ficticio', 'issue_date' => '2026-11-05', 'tax_mode' => 'included',
            'products_text' => 'Servicio ficticio 12630 soles', 'payment_method' => 'credito',
            'outstanding_amount' => '12630.00', 'installments' => [['due_date' => '2026-12-05', 'amount' => '12630.00']],
        ];
    }

    private function updatePayload(): array
    {
        return [...$this->payload(), 'currency' => 'PEN', 'items' => [['description' => 'Servicio ficticio', 'quantity' => '1', 'unit_price' => '12630.00']]];
    }

    public function test_credit_persists_and_produces_real_greenter_payment_terms_without_network(): void
    {
        $id = $this->postJson('/api/invoice-drafts/import', $this->payload())->assertCreated()
            ->assertJsonPath('data.payment_method', 'credito')
            ->assertJsonPath('data.outstanding_amount', '12630.00')
            ->assertJsonPath('data.installments.0.due_date', '2026-12-05')->json('data.id');
        $draft = InvoiceDraft::findOrFail($id)->load(['company', 'items']);
        $invoice = new Invoice(['document_type' => '01', 'series' => 'F001', 'correlative' => 1]);
        $method = new ReflectionMethod(GreenterSunatGateway::class, 'makeInvoice');
        $model = $method->invoke(app(GreenterSunatGateway::class), $draft, $invoice, $draft->company);
        $xml = (new InvoiceBuilder)->build($model);
        $doc = new DOMDocument;
        $this->assertTrue($doc->loadXML($xml));
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $this->assertSame('Credito', $xpath->evaluate('string(//cac:PaymentTerms[1]/cbc:PaymentMeansID)'));
        $this->assertSame('12630.00', $xpath->evaluate('string(//cac:PaymentTerms[1]/cbc:Amount)'));
        $this->assertSame('PEN', $xpath->evaluate('string(//cac:PaymentTerms[1]/cbc:Amount/@currencyID)'));
        $this->assertSame('Cuota001', $xpath->evaluate('string(//cac:PaymentTerms[2]/cbc:PaymentMeansID)'));
        $this->assertSame('2026-12-05', $xpath->evaluate('string(//cac:PaymentTerms[2]/cbc:PaymentDueDate)'));
        $this->assertSame('12630.00', $xpath->evaluate('string(//cac:PaymentTerms[2]/cbc:Amount)'));
        $issued = $this->postJson('/api/invoice-drafts/'.$id.'/issue')->assertCreated()->json('data.id');
        $pdf = $this->get('/api/invoices/'.$issued.'/files/pdf')->assertOk()->getContent();
        $this->assertStringContainsString(iconv('UTF-8', 'Windows-1252', '(CRÉDITO)'), $pdf);
        $this->assertStringContainsString('(S/ 12,630.00)', $pdf);
        $this->assertStringContainsString('(05/12/2026  '.iconv('UTF-8', 'Windows-1252', '·').'  S/ 12,630.00)', $pdf);
        $this->putJson('/api/invoice-drafts/'.$id, $this->updatePayload())->assertConflict();
        Http::assertNothingSent();
    }

    public function test_partial_balance_and_multiple_installments_survive_updates_and_reopening(): void
    {
        $payload = [...$this->payload(), 'outstanding_amount' => '6630.00', 'installments' => [
            ['due_date' => '2026-12-05', 'amount' => '3300.00'], ['due_date' => '2027-01-05', 'amount' => '3330.00'],
        ]];
        $id = $this->postJson('/api/invoice-drafts/import', $payload)->assertCreated()->json('data.id');
        $update = $this->updatePayload();
        unset($update['payment_method'], $update['outstanding_amount'], $update['installments']);
        $this->putJson('/api/invoice-drafts/'.$id, $update)->assertOk()->assertJsonPath('data.outstanding_amount', '6630.00');
        $this->getJson('/api/invoice-drafts/'.$id)->assertOk()->assertJsonCount(2, 'data.installments');
        $this->putJson('/api/invoice-drafts/'.$id, [...$this->updatePayload(), 'outstanding_amount' => '12631.00'])
            ->assertUnprocessable();
        $this->assertSame('6630.00', InvoiceDraft::findOrFail($id)->outstanding_amount);
        $this->putJson('/api/invoice-drafts/'.$id, [...$update, 'payment_method' => 'contado', 'outstanding_amount' => null, 'installments' => []])
            ->assertOk()->assertJsonPath('data.payment_method', 'contado')->assertJsonPath('data.outstanding_amount', null);
    }

    public function test_legacy_callers_remain_contado_and_issue_guard_blocks_invalid_credit_before_sequence(): void
    {
        $payload = $this->payload();
        unset($payload['payment_method'], $payload['outstanding_amount'], $payload['installments']);
        $id = $this->postJson('/api/invoice-drafts/import', $payload)->assertCreated()->assertJsonPath('data.payment_method', 'contado')->json('data.id');
        InvoiceDraft::findOrFail($id)->update(['payment_method' => 'credito', 'outstanding_amount' => '12630.00', 'installments' => []]);
        $this->postJson('/api/invoice-drafts/'.$id.'/issue')->assertUnprocessable();
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, InvoiceSequence::count());
    }

    #[DataProvider('invalidTerms')]
    public function test_invalid_payment_terms_are_rejected(array $changes): void
    {
        $this->postJson('/api/invoice-drafts/import', [...$this->payload(), ...$changes])->assertUnprocessable();
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, InvoiceSequence::count());
    }

    public static function invalidTerms(): iterable
    {
        yield 'missing installments' => [['installments' => []]];
        yield 'wrong sum' => [['installments' => [['due_date' => '2026-12-05', 'amount' => '1.00']]]];
        yield 'same day' => [['installments' => [['due_date' => '2026-11-05', 'amount' => '12630.00']]]];
        yield 'invalid calendar' => [['installments' => [['due_date' => '2026-11-31', 'amount' => '12630.00']]]];
        yield 'negative balance' => [['outstanding_amount' => '-1.00']];
        yield 'excess balance' => [['outstanding_amount' => '12631.00']];
        yield 'numeric JSON amount' => [['outstanding_amount' => 12630]];
        yield 'contado with credit data' => [['payment_method' => 'contado']];
        yield 'boleta credit unsupported' => [['document_type' => '03']];
        yield 'duplicate dates' => [['installments' => [['due_date' => '2026-12-05', 'amount' => '630.00'], ['due_date' => '2026-12-05', 'amount' => '12000.00']]]];
        yield 'too many' => [['installments' => array_fill(0, 13, ['due_date' => '2026-12-05', 'amount' => '1.00'])]];
    }
}
