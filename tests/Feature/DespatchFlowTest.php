<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Despatch;
use App\Models\Invoice;
use App\Models\InvoiceDraft;
use App\Models\InvoiceSequence;
use App\Services\CompanyApiTokenService;
use App\Services\CompanyCertificateStore;
use App\Services\GreenterDespatchGateway;
use App\Services\GreSendException;
use DOMDocument;
use DOMXPath;
use Greenter\Api;
use Greenter\Sunat\GRE\Api\CpeApiInterface;
use Greenter\Sunat\GRE\ApiException;
use Greenter\Sunat\GRE\Model\CpeResponse;
use Greenter\Model\Response\CdrResponse;
use Greenter\Model\Response\StatusResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DespatchFlowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-05 10:00:00', 'America/Lima'));
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->company = Company::create(['ruc' => '20111111111', 'legal_name' => 'Empresa ficticia',
            'ubigeo' => '150101', 'department' => 'LIMA', 'province' => 'LIMA', 'district' => 'LIMA',
            'address' => 'Prueba 123', 'sunat_driver' => 'fake', 'sunat_environment' => 'beta', 'default_series' => 'F001', 'active' => true]);
        $this->withToken(app(CompanyApiTokenService::class)->create($this->company, 'Pruebas')['plain_text']);
        $draft = InvoiceDraft::create(['company_id' => $this->company->id, 'document_type' => '01',
            'customer_document_type' => '6', 'customer_ruc' => '20222222222', 'customer_name' => 'Cliente ficticio',
            'issue_date' => '2026-11-05', 'tax_mode' => 'included', 'currency' => 'PEN', 'status' => 'issued', 'ai_driver' => 'demo',
            'source_path' => 'fixture.txt', 'original_name' => 'fixture.txt', 'mime_type' => 'text/plain']);
        $this->invoice = Invoice::create(['company_id' => $this->company->id, 'invoice_draft_id' => $draft->id,
            'document_type' => '01', 'sunat_environment' => 'beta', 'series' => 'F001', 'correlative' => 1, 'status' => 'accepted']);
    }

    private function payload(string $key = 'guide-fixture-1'): array
    {
        return ['idempotency_key' => $key, 'invoice_id' => $this->invoice->id, 'issue_date' => '2026-11-05', 'transfer_date' => '2026-11-06',
            'origin' => ['ubigeo' => '150101', 'address' => 'Origen & prueba'],
            'destination' => ['ubigeo' => '150132', 'address' => 'Destino ficticio'],
            'weight_kg' => '25.500', 'vehicle' => ['plate' => 'ABC123'],
            'driver' => ['dni' => '12345678', 'first_name' => 'Nombre & prueba', 'last_name' => 'Apellido', 'license' => 'Q12345678'],
            'items' => [['description' => 'Bien ficticio ]]> seguro', 'quantity' => '4', 'unit' => 'NIU']]];
    }

    public function test_review_confirmation_pending_acceptance_and_idempotency_are_separate(): void
    {
        $data = $this->postJson('/api/despatches', $this->payload())->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.is_demo', true)->json('data');
        $this->assertSame(0, InvoiceSequence::count());
        $this->postJson('/api/despatches', $this->payload())->assertCreated()->assertJsonPath('data.id', $data['id']);
        $this->postJson('/api/despatches', [...$this->payload(), 'weight_kg' => '30'])->assertConflict();
        $path = '/api/despatches/'.$data['id'];
        $this->postJson($path.'/issue', ['confirmed' => true, 'confirmation_digest' => str_repeat('0', 64)])->assertConflict();
        $this->postJson($path.'/issue', ['confirmation_digest' => $data['confirmation_digest']])->assertUnprocessable();
        $confirm = ['confirmed' => true, 'confirmation_digest' => $data['confirmation_digest']];
        $this->postJson($path.'/issue', $confirm)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->postJson($path.'/issue', $confirm)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame(2, InvoiceSequence::firstOrFail()->next_number);
        $guide = Despatch::findOrFail($data['id']);
        $xml = Storage::disk('local')->get($guide->xml_path);
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml));
        $xp = new DOMXPath($document);
        $xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $this->assertSame('09', $xp->evaluate('string(//cbc:DespatchAdviceTypeCode)'));
        $this->assertSame('02', $xp->evaluate('string(//cbc:TransportModeCode)'));
        $this->assertSame('2026-11-06', $xp->evaluate('string(//cbc:StartDate)'));
        $this->assertStringContainsString('Nombre &amp; prueba', $xml);
        $this->postJson($path.'/status')->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.message', 'SIMULACIÓN local: no enviada a SUNAT.');
        $this->get($path.'/files/xml')->assertOk();
        $this->get($path.'/files/cdr')->assertOk();
        Http::assertNothingSent();
    }

    public function test_invalid_data_cross_company_and_changed_configuration_cannot_issue(): void
    {
        $invalid = $this->payload();
        unset($invalid['driver']['license']);
        $this->postJson('/api/despatches', $invalid)->assertUnprocessable();
        $this->postJson('/api/despatches', [...$this->payload(), 'transfer_date' => '2026-11-04'])->assertUnprocessable();
        $this->postJson('/api/despatches', [...$this->payload(), 'issue_date' => '2026-11-06'])->assertUnprocessable();
        $other = Company::create(['ruc' => '20333333333', 'legal_name' => 'Otra', 'ubigeo' => '150101',
            'department' => 'LIMA', 'province' => 'LIMA', 'district' => 'LIMA', 'address' => 'Otra dirección',
            'sunat_driver' => 'fake', 'sunat_environment' => 'beta', 'active' => true]);
        $this->invoice->update(['company_id' => $other->id]);
        $this->postJson('/api/despatches', $this->payload())->assertNotFound();
        $this->invoice->update(['company_id' => $this->company->id]);
        $data = $this->postJson('/api/despatches', $this->payload())->assertCreated()->json('data');
        $this->company->update(['gre_revision' => 1]);
        $this->postJson('/api/despatches/'.$data['id'].'/issue', ['confirmed' => true, 'confirmation_digest' => $data['confirmation_digest']])->assertConflict();
        $this->assertSame(0, InvoiceSequence::count());
        $this->withToken(app(CompanyApiTokenService::class)->create($other, 'Otra')['plain_text']);
        $this->getJson('/api/despatches/'.$data['id'])->assertNotFound();
    }

    public function test_m1_and_l_use_the_explicit_indicator_without_vehicle_or_driver_tags(): void
    {
        foreach (['M1', 'L'] as $category) {
            $payload = [...$this->payload('light-'.$category), 'vehicle_category' => $category];
            unset($payload['vehicle'], $payload['driver']);
            if ($category === 'L') {
                $payload['vehicle'] = null;
                $payload['driver'] = [];
            }
            $data = $this->postJson('/api/despatches', $payload)->assertCreated()
                ->assertJsonPath('data.payload.vehicle_category', $category)
                ->assertJsonMissingPath('data.payload.vehicle')->assertJsonMissingPath('data.payload.driver')->json('data');
            $this->postJson('/api/despatches/'.$data['id'].'/issue', ['confirmed' => true, 'confirmation_digest' => $data['confirmation_digest']])
                ->assertOk()->assertJsonPath('data.status', 'pending');
            $guide = Despatch::findOrFail($data['id']);
            $document = new DOMDocument;
            $this->assertTrue($document->loadXML(Storage::disk('local')->get($guide->xml_path)));
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
            $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
            $this->assertSame('SUNAT_Envio_IndicadorTrasladoVehiculoM1L', $xpath->evaluate('string(//cbc:SpecialInstructions)'));
            $this->assertSame(0.0, $xpath->evaluate('count(//cac:DriverPerson)'));
            $this->assertSame(0.0, $xpath->evaluate('count(//cac:TransportEquipment)'));
        }
        Http::assertNothingSent();
    }

    public function test_category_is_not_inferred_and_changing_it_invalidates_the_previous_confirmation(): void
    {
        $normal = [...$this->payload(), 'vehicle_category' => 'other'];
        $created = $this->postJson('/api/despatches', $normal)->assertCreated()->json('data');
        unset($normal['vehicle'], $normal['driver']);
        $this->postJson('/api/despatches', $normal)->assertUnprocessable();
        $this->postJson('/api/despatches', [...$normal, 'vehicle_category' => 'unknown'])->assertUnprocessable();
        $this->postJson('/api/despatches', [...$this->payload(), 'vehicle_category' => 'M1'])->assertUnprocessable();
        $this->postJson('/api/despatches', [...$normal, 'vehicle_category' => 'M1'])->assertConflict();
        $light = $this->postJson('/api/despatches', [...$normal, 'idempotency_key' => 'new-category', 'vehicle_category' => 'M1'])
            ->assertCreated()->json('data');
        $this->assertNotSame($created['confirmation_digest'], $light['confirmation_digest']);
        $this->postJson('/api/despatches/'.$light['id'].'/issue', ['confirmed' => true, 'confirmation_digest' => $created['confirmation_digest']])->assertConflict();
        $this->assertSame(0, InvoiceSequence::count());
    }

    public function test_unconfirmed_send_never_resends_and_query_failure_preserves_ticket(): void
    {
        $this->mock(GreenterDespatchGateway::class, function ($mock): void {
            $mock->shouldReceive('xml')->once()->andReturn('<DespatchAdvice/>');
            $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('fixture timeout'));
        });
        $data = $this->postJson('/api/despatches', $this->payload())->json('data');
        $confirm = ['confirmed' => true, 'confirmation_digest' => $data['confirmation_digest']];
        $path = '/api/despatches/'.$data['id'];
        $this->postJson($path.'/issue', $confirm)->assertOk()->assertJsonPath('data.status', 'unknown');
        $this->postJson($path.'/issue', $confirm)->assertOk()->assertJsonPath('data.status', 'unknown');
        Despatch::findOrFail($data['id'])->update(['status' => 'pending', 'ticket' => 'fixture-ticket']);
        $this->mock(GreenterDespatchGateway::class, fn ($mock) => $mock->shouldReceive('status')->once()->andThrow(new \RuntimeException('fixture query failure')));
        $this->postJson($path.'/status')->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.ticket', 'fixture-ticket');
    }

    public function test_gre_credentials_are_encrypted_hidden_and_cannot_send_beta_to_production(): void
    {
        config()->set('facturaya.platform.admin_token', 'fixture-admin');
        // Test model storage independently of the platform token deployment configuration.
        $this->company->update(['gre_client_id' => 'fixture-id', 'gre_client_secret' => 'fixture-secret', 'gre_enabled' => true]);
        $stored = DB::table('companies')->where('id', $this->company->id)->first();
        $this->assertNotSame('fixture-secret', $stored->gre_client_secret);
        $this->assertStringNotContainsString('fixture-secret', $this->company->fresh()->toJson());
        $this->assertStringNotContainsString('fixture-id', $this->company->fresh()->toJson());
        $this->company->update(['sunat_driver' => 'greenter']);
        $data = $this->postJson('/api/despatches', $this->payload())->assertCreated()->json('data');
        $this->postJson('/api/despatches/'.$data['id'].'/issue', ['confirmed' => true, 'confirmation_digest' => $data['confirmation_digest']])->assertUnprocessable();
        $this->assertSame(0, InvoiceSequence::count());
        Http::assertNothingSent();
    }

    public function test_only_admin_can_configure_credentials_and_response_never_contains_them(): void
    {
        config()->set('facturaya.platform.admin_token', 'fixture-admin');
        $path = '/api/admin/companies/'.$this->company->id.'/gre-credentials';
        $values = ['gre_enabled' => true, 'gre_client_id' => 'fixture-id', 'gre_client_secret' => 'fixture-secret', 'next_number' => 17];
        $this->putJson($path, $values)->assertUnauthorized();
        $response = $this->withToken('fixture-admin')->putJson($path, $values)->assertOk()
            ->assertJsonPath('data.gre_credentials_configured', true)->assertJsonPath('data.gre_enabled', true);
        $this->assertStringNotContainsString('fixture-id', $response->getContent());
        $this->assertStringNotContainsString('fixture-secret', $response->getContent());
        $this->assertSame(1, $this->company->fresh()->gre_revision);
        $this->putJson($path, ['gre_enabled' => true, 'next_number' => 16])->assertUnprocessable();
        $this->putJson($path, ['gre_enabled' => false, 'gre_client_id' => null, 'gre_client_secret' => null])->assertOk()
            ->assertJsonPath('data.gre_credentials_configured', false);
        $this->assertNull($this->company->fresh()->gre_client_secret);
    }

    public function test_real_gateway_requires_matching_complete_cdr_and_preserves_pending(): void
    {
        $api = \Mockery::mock(Api::class);
        $gateway = \Mockery::mock(GreenterDespatchGateway::class, [app(CompanyCertificateStore::class)])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $gateway->shouldReceive('api')->andReturn($api);
        $guide = new Despatch(['driver' => 'greenter', 'series' => 'T001', 'correlative' => 1, 'ticket' => 'fixture-ticket']);
        $api->shouldReceive('getStatus')->once()->andReturn((new StatusResult)->setCode('98'));
        $this->assertSame('pending', $gateway->status($this->company, $guide)['status']);
        $cdr = (new CdrResponse)->setId('T001-1')->setCode('0');
        $result = (new StatusResult)->setCode('0')->setSuccess(true)->setCdrResponse($cdr)->setCdrZip('fixture-cdr');
        $api->shouldReceive('getStatus')->once()->andReturn($result);
        $this->assertSame('accepted', $gateway->status($this->company, $guide)['status']);
        $cdr->setCode(null);
        $api->shouldReceive('getStatus')->once()->andReturn($result);
        try {
            $gateway->status($this->company, $guide);
            $this->fail('Missing CDR code must not become accepted by integer casting.');
        } catch (\RuntimeException $error) {
            $this->assertSame('gre_status_unconfirmed', $error->getMessage());
        }
        $cdr->setCode('0');
        $cdr->setId('T001-999');
        $api->shouldReceive('getStatus')->once()->andReturn($result);
        $this->expectException(\RuntimeException::class);
        $gateway->status($this->company, $guide);
    }

    public function test_sunat_rejection_without_ticket_is_recorded_as_not_sent_and_never_resent(): void
    {
        $this->mock(GreenterDespatchGateway::class, function ($mock): void {
            $mock->shouldReceive('xml')->once()->andReturn('<DespatchAdvice/>');
            $mock->shouldReceive('send')->once()->andThrow(new GreSendException(true, '2335', 'El ubigeo de llegada no es válido', 'send'));
        });
        $data = $this->postJson('/api/despatches', $this->payload())->json('data');
        $confirm = ['confirmed' => true, 'confirmation_digest' => $data['confirmation_digest']];
        $path = '/api/despatches/'.$data['id'];
        $this->postJson($path.'/issue', $confirm)->assertOk()->assertJsonPath('data.status', 'send_failed')
            ->assertJsonPath('data.message', 'SUNAT no recibió la guía (2335: El ubigeo de llegada no es válido). No quedó registrada: corrige el dato y prepara una nueva guía.');
        $this->assertSame('2335', Despatch::findOrFail($data['id'])->response_code);
        $this->postJson($path.'/issue', $confirm)->assertOk()->assertJsonPath('data.status', 'send_failed');
    }

    public function test_uncertain_sunat_failure_keeps_unknown_and_shows_the_reason(): void
    {
        $this->mock(GreenterDespatchGateway::class, function ($mock): void {
            $mock->shouldReceive('xml')->once()->andReturn('<DespatchAdvice/>');
            $mock->shouldReceive('send')->once()->andThrow(new GreSendException(false, 'HTTP_500', 'Error interno', 'send'));
        });
        $data = $this->postJson('/api/despatches', $this->payload())->json('data');
        $this->postJson('/api/despatches/'.$data['id'].'/issue', ['confirmed' => true, 'confirmation_digest' => $data['confirmation_digest']])
            ->assertOk()->assertJsonPath('data.status', 'unknown')
            ->assertJsonPath('data.message', 'No pudimos confirmar el envío (HTTP_500: Error interno). No vuelvas a emitir esta guía; requiere revisión.');
    }

    public function test_real_gateway_classifies_sunat_failures_and_hides_token_like_values(): void
    {
        $cpe = \Mockery::mock(CpeApiInterface::class);
        $gateway = \Mockery::mock(GreenterDespatchGateway::class, [app(CompanyCertificateStore::class)])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $gateway->shouldReceive('cpe')->andReturn($cpe);
        $guide = new Despatch(['driver' => 'greenter', 'series' => 'T001', 'correlative' => 1]);
        $attempt = function () use ($gateway, $guide): GreSendException {
            try {
                $gateway->send($this->company, $guide, '<DespatchAdvice/>');
            } catch (GreSendException $error) {
                return $error;
            }
            $this->fail('A send without ticket must raise GreSendException.');
        };

        $cpe->shouldReceive('enviarCpe')->once()->andThrow(new ApiException('[422] rejected', 422, [],
            '{"cod":"422","msg":"Validacion","errors":[{"cod":"2335","msg":"El ubigeo de llegada no es válido"}]}'));
        $error = $attempt();
        $this->assertTrue($error->definitive);
        $this->assertSame(['2335', 'El ubigeo de llegada no es válido', 'send'], [$error->sunatCode, $error->sunatMessage, $error->stage]);

        $cpe->shouldReceive('enviarCpe')->once()->andThrow(new ApiException('[500] server', 500, [], '{"cod":"500","msg":"Error interno"}'));
        $this->assertFalse($attempt()->definitive);

        $cpe->shouldReceive('enviarCpe')->once()->andThrow(new ApiException('[422] dup', 422, [],
            '{"errors":[{"cod":"1033","msg":"El comprobante fue registrado previamente con otros datos"}]}'));
        $this->assertFalse($attempt()->definitive);

        $cpe->shouldReceive('enviarCpe')->once()->andThrow(new ApiException('[0] connect', 0));
        $error = $attempt();
        $this->assertFalse($error->definitive);
        $this->assertSame('SIN_RESPUESTA', $error->sunatCode);

        $cpe->shouldReceive('enviarCpe')->once()->andReturn((new CpeResponse)->setNumTicket('ticket-123'));
        $this->assertSame('ticket-123', $gateway->send($this->company, $guide, '<DespatchAdvice/>'));

        $token = new ApiException('[401] token', 401, [], '{"error":"invalid_client","error_description":"Bearer abcdefghijklmnopqrstuvwxyz0123456789ABCDEF expirado"}');
        $this->assertSame(['invalid_client', 'Bearer […] expirado'], GreenterDespatchGateway::sunatError($token));
        $denied = \Mockery::mock(GreenterDespatchGateway::class, [app(CompanyCertificateStore::class)])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $denied->shouldReceive('cpe')->andThrow($token);
        try {
            $denied->send($this->company, $guide, '<DespatchAdvice/>');
            $this->fail('A failed token request must raise GreSendException.');
        } catch (GreSendException $error) {
            $this->assertTrue($error->definitive);
            $this->assertSame('auth', $error->stage);
        }
    }

    private function invoiceFor(Company $company, int $correlative, array $draft = [], array $invoice = []): Invoice
    {
        $stored = InvoiceDraft::create(['company_id' => $company->id, 'document_type' => '01', 'customer_document_type' => '6',
            'customer_ruc' => '20444444444', 'customer_name' => 'Otro cliente', 'issue_date' => '2026-11-05', 'tax_mode' => 'included',
            'currency' => 'PEN', 'status' => 'issued', 'ai_driver' => 'demo', 'source_path' => 'fixture.txt',
            'original_name' => 'fixture.txt', 'mime_type' => 'text/plain', ...$draft]);

        return Invoice::create(['company_id' => $company->id, 'invoice_draft_id' => $stored->id, 'document_type' => '01',
            'sunat_environment' => 'beta', 'series' => 'F001', 'correlative' => $correlative, 'status' => 'accepted', ...$invoice]);
    }

    public function test_web_page_lists_only_invoices_a_guide_can_reference_with_their_goods(): void
    {
        $this->withoutVite();
        $this->get('/guias')->assertOk()->assertSee('Guías de remisión')->assertSee('"PR":"Par"', false);
        foreach ([[1, '56.000', 'Cascos de seguridad'], [2, '7.250', 'Cinta']] as [$position, $quantity, $description]) {
            $this->invoice->draft->items()->create(['position' => $position, 'description' => $description, 'quantity' => $quantity,
                'entered_unit_price' => 1, 'unit_value' => 1, 'unit_price_with_igv' => 1, 'line_base' => 1, 'igv' => 0, 'line_total' => 1]);
        }
        $this->invoiceFor($this->company, 2, ['document_type' => '03', 'customer_document_type' => '1'], ['document_type' => '03', 'series' => 'B001']);
        $this->invoiceFor($this->company, 3, [], ['status' => 'rejected']);
        $this->invoiceFor($this->company, 4, ['customer_document_type' => '1']);
        $this->invoiceFor($this->company, 5, [], ['sunat_environment' => 'production']);
        $other = Company::create(['ruc' => '20333333333', 'legal_name' => 'Otra', 'ubigeo' => '150101', 'department' => 'LIMA',
            'province' => 'LIMA', 'district' => 'LIMA', 'address' => 'Otra dirección', 'sunat_driver' => 'fake', 'sunat_environment' => 'beta', 'active' => true]);
        $this->invoiceFor($other, 6);

        $this->getJson('/api/despatch-invoices')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'F001-00000001')->assertJsonPath('data.0.customer.ruc', '20222222222')
            ->assertJsonPath('data.0.items', [['description' => 'Cascos de seguridad', 'quantity' => '56'], ['description' => 'Cinta', 'quantity' => '7.25']])
            ->assertJsonPath('data.0.guides', []);

        $data = $this->postJson('/api/despatches', $this->payload())->assertCreated()->json('data');
        $this->postJson('/api/despatches/'.$data['id'].'/issue', ['confirmed' => true, 'confirmation_digest' => $data['confirmation_digest']])->assertOk();
        $this->getJson('/api/despatch-invoices')->assertJsonPath('data.0.guides', [['number' => 'T001-1', 'status' => 'pending']]);
        Http::assertNothingSent();
    }

    public function test_goods_accept_catalog_units_and_guides_show_their_invoice_and_recipient(): void
    {
        $payload = $this->payload();
        $payload['items'] = [['description' => 'Guantes', 'quantity' => '40', 'unit' => 'PR'], ['description' => 'Cemento', 'quantity' => '271', 'unit' => 'BG']];
        $this->postJson('/api/despatches', $payload)->assertCreated()->assertJsonPath('data.payload.items.0.unit', 'PR')
            ->assertJsonPath('data.invoice_number', 'F001-00000001')->assertJsonPath('data.customer.name', 'Cliente ficticio');
        $payload['idempotency_key'] = 'guide-fixture-unit';
        $payload['items'][0]['unit'] = 'PAR';
        $this->postJson('/api/despatches', $payload)->assertUnprocessable()->assertJsonValidationErrors('items.0.unit');

        $this->getJson('/api/despatches')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.invoice_number', 'F001-00000001')->assertJsonPath('data.0.customer.ruc', '20222222222')
            ->assertJsonMissingPath('data.0.invoice')->assertJsonMissingPath('data.0.xml_path');
    }
}
