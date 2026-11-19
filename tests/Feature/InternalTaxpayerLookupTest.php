<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class InternalTaxpayerLookupTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'profile-bridge-test-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'company_profile.enabled' => true,
            'company_profile.allow_local_http' => true,
            'company_profile.bridge_token' => self::TOKEN,
            'facturaya.ruc_lookup.api_peru_token' => 'api-peru-test-token',
        ]);
    }

    private function fakeProvider(): void
    {
        Http::fake([
            'https://api.apiperu.dev/ruc' => Http::response([
                'success' => true,
                'data' => [
                    'ruc' => '20557288016',
                    'nombre_o_razon_social' => 'AGU BELLO E.I.R.L.',
                    'estado' => 'ACTIVO',
                    'condicion' => 'HABIDO',
                    'direccion_completa' => 'LIMA - LIMA - SAN JUAN DE LURIGANCHO',
                    'ubigeo_sunat' => '150132',
                ],
            ]),
        ]);
    }

    public function test_returns_the_taxpayer_without_creating_customers_and_caches_it(): void
    {
        $this->fakeProvider();

        $this->withToken(self::TOKEN)
            ->getJson('/api/internal/taxpayers/20557288016')
            ->assertOk()
            ->assertExactJson(['data' => [
                'ruc' => '20557288016',
                'legal_name' => 'AGU BELLO E.I.R.L.',
                'status' => 'ACTIVO',
                'condition' => 'HABIDO',
                'address' => 'LIMA - LIMA - SAN JUAN DE LURIGANCHO',
                'ubigeo' => '150132',
            ]]);

        $this->withToken(self::TOKEN)->getJson('/api/internal/taxpayers/20557288016')->assertOk();

        Http::assertSentCount(1);
        $this->assertSame(0, Customer::query()->count());
        $this->assertDatabaseHas('sunat_taxpayers', ['ruc' => '20557288016']);
    }

    public function test_requires_the_internal_bearer(): void
    {
        $this->fakeProvider();

        $this->getJson('/api/internal/taxpayers/20557288016')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/internal/taxpayers/20557288016')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_rejects_malformed_rucs_and_reports_missing_ones(): void
    {
        Http::fake([
            'https://api.apiperu.dev/ruc' => Http::response(['success' => false, 'message' => 'No encontrado'], 404),
            'https://openruc.com/*' => Http::response([], 404),
        ]);

        $this->withToken(self::TOKEN)->getJson('/api/internal/taxpayers/2055728801')->assertNotFound();
        $this->withToken(self::TOKEN)->getJson('/api/internal/taxpayers/20111111111')->assertNotFound()
            ->assertExactJson(['error' => 'not_found']);
    }

    public function test_is_unavailable_when_every_provider_fails(): void
    {
        Http::fake([
            'https://api.apiperu.dev/ruc' => Http::response(['code' => 'quota_exceeded'], 429),
            'https://openruc.com/*' => Http::response([], 500),
        ]);

        $this->withToken(self::TOKEN)->getJson('/api/internal/taxpayers/20557288016')
            ->assertStatus(503)
            ->assertExactJson(['error' => 'lookup_unavailable']);
    }
}
