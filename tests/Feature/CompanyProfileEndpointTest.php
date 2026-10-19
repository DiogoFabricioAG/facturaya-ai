<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\CompanyCertificateStore;
use App\Services\CompanyProfileSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CompanyCertificateFixture;
use Tests\TestCase;

final class CompanyProfileEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 'c1f2e3d4-b5a6-4789-8012-3456789abcde';

    private const TENANT_B = 'd1f2e3d4-b5a6-4789-8012-3456789abcdf';

    private const PROFILE_TOKEN = 'profile-bridge-test-token';

    private CompanyProfileSyncService $profiles;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'company_profile.enabled' => true,
            'company_profile.allow_local_http' => true,
            'company_profile.bridge_token' => self::PROFILE_TOKEN,
            'facturaya.platform.admin_token' => 'admin-test-token',
        ]);

        Storage::fake('local');
        $this->profiles = app(CompanyProfileSyncService::class);
    }

    private function makeCompany(bool $withCertificate = true): Company
    {
        $company = Company::create([
            'ruc' => '20111111111',
            'legal_name' => 'Empresa de perfil',
            'ubigeo' => '150101',
            'department' => 'LIMA',
            'province' => 'LIMA',
            'district' => 'LIMA',
            'address' => 'Av. Perfil 123',
            'sunat_driver' => 'greenter',
            'sunat_environment' => 'production',
            'sol_user' => 'MODDATOS',
            'sol_password' => 'synthetic-sol-secret',
            'default_series' => 'F001',
            'default_boleta_series' => 'B001',
            'active' => true,
        ]);

        if ($withCertificate) {
            $company->update(['certificate_path' => CompanyCertificateFixture::storeFor($company)]);
        }

        return $company->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fiscalPayload(Company $company, array $overrides = []): array
    {
        [$pkcs12, $password] = CompanyCertificateFixture::makePkcs12('synthetic-passphrase');

        return array_merge([
            'tenant_id' => self::TENANT_A,
            'config_version' => 1,
            'ruc' => (string) $company->ruc,
            'sol_username' => 'MODDATOS',
            'sol_password' => 'synthetic-sol-secret',
            'certificate_b64' => base64_encode($pkcs12),
            'certificate_passphrase' => $password,
            'expected_profile_version' => 0,
            'idempotency_key' => 'fiscal-key-'.bin2hex(random_bytes(4)),
            'environment' => 'production',
        ], $overrides);
    }

    /**
     * @return array<string, string>
     */
    private function profileHeaders(string $tenantId): array
    {
        return [
            'Authorization' => 'Bearer '.self::PROFILE_TOKEN,
            'X-MeowLab-Tenant-ID' => $tenantId,
        ];
    }

    public function test_get_returns_persisted_binding_and_snapshot_without_echo(): void
    {
        $company = $this->makeCompany();
        $this->profiles->refresh($company);
        $this->profiles->bind($company, self::TENANT_A, 0, 'bind-key-1');

        $response = $this->getJson(
            '/api/internal/company-profile/'.$company->id,
            $this->profileHeaders(self::TENANT_A),
        );

        $response->assertOk();
        $data = $response->json('data');

        $this->assertSame(self::TENANT_A, $data['tenant_id']);
        $this->assertSame($company->id, $data['company_ref']);
        $this->assertSame('production', $data['environment']);
        $this->assertSame(1, $data['profile_version']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $data['fiscal_digest']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $data['certificate_sha256']);
        $this->assertSame('20111111111', $data['ruc']);
        $this->assertArrayHasKey('captured_at', $data);
        $this->assertSame('MO***S', $data['sol_username_masked']);

        foreach (['sol_user', 'sol_password', 'passphrase', 'certificate_bytes'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data);
        }
    }

    public function test_get_without_binding_is_not_found(): void
    {
        $company = $this->makeCompany();

        $this->getJson(
            '/api/internal/company-profile/'.$company->id,
            $this->profileHeaders(self::TENANT_A),
        )->assertNotFound();
    }

    public function test_get_with_a_different_tenant_header_is_not_found(): void
    {
        $company = $this->makeCompany();
        $this->profiles->refresh($company);
        $this->profiles->bind($company, self::TENANT_A, 0, 'bind-key-2');

        $this->getJson(
            '/api/internal/company-profile/'.$company->id,
            $this->profileHeaders(self::TENANT_B),
        )->assertNotFound();
    }

    public function test_get_requires_the_dedicated_bridge_token(): void
    {
        $company = $this->makeCompany();

        $this->getJson('/api/internal/company-profile/'.$company->id, [
            'X-MeowLab-Tenant-ID' => self::TENANT_A,
        ])->assertUnauthorized();

        config(['company_profile.enabled' => false]);

        $this->getJson(
            '/api/internal/company-profile/'.$company->id,
            $this->profileHeaders(self::TENANT_A),
        )->assertStatus(503);
    }

    public function test_sync_creates_the_binding_and_replays_idempotently(): void
    {
        $company = $this->makeCompany();
        $this->profiles->refresh($company);

        $payload = [
            'tenant_id' => self::TENANT_A,
            'expected_profile_version' => 0,
            'idempotency_key' => 'sync-key-1',
        ];

        $this->postJson(
            '/api/internal/company-profile/'.$company->id.'/sync',
            $payload,
            $this->profileHeaders(self::TENANT_A),
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'created')
            ->assertJsonPath('data.profile_version', 1)
            ->assertJsonPath('data.tenant_id', self::TENANT_A);

        $this->postJson(
            '/api/internal/company-profile/'.$company->id.'/sync',
            $payload,
            $this->profileHeaders(self::TENANT_A),
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'replayed')
            ->assertJsonPath('data.replayed', true);
    }

    public function test_sync_rejects_a_second_tenant_for_the_same_company(): void
    {
        $company = $this->makeCompany();
        $this->profiles->refresh($company);
        $this->profiles->bind($company, self::TENANT_A, 0, 'bind-key-3');

        $this->postJson(
            '/api/internal/company-profile/'.$company->id.'/sync',
            [
                'tenant_id' => self::TENANT_B,
                'expected_profile_version' => 1,
                'idempotency_key' => 'sync-key-2',
            ],
            $this->profileHeaders(self::TENANT_B),
        )
            ->assertStatus(409)
            ->assertJsonPath('data.status', 'binding_conflict')
            ->assertJsonPath('data.reason', 'company_already_bound_to_other_tenant');
    }

    public function test_sync_conflicts_on_stale_version_after_a_fiscal_change(): void
    {
        $company = $this->makeCompany();
        $this->profiles->refresh($company);
        $this->profiles->bind($company, self::TENANT_A, 0, 'bind-key-4');

        $company->forceFill(['fiscal_revision' => 1])->save();
        $this->profiles->refresh($company->fresh());

        $this->postJson(
            '/api/internal/company-profile/'.$company->id.'/sync',
            [
                'tenant_id' => self::TENANT_A,
                'expected_profile_version' => 1,
                'idempotency_key' => 'sync-key-3',
            ],
            $this->profileHeaders(self::TENANT_A),
        )
            ->assertStatus(409)
            ->assertJsonPath('data.status', 'version_conflict');

        $this->postJson(
            '/api/internal/company-profile/'.$company->id.'/sync',
            [
                'tenant_id' => self::TENANT_A,
                'expected_profile_version' => 2,
                'idempotency_key' => 'sync-key-4',
            ],
            $this->profileHeaders(self::TENANT_A),
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'updated')
            ->assertJsonPath('data.profile_version', 2);
    }

    public function test_admin_fiscal_update_bumps_revision_and_profile_version(): void
    {
        $company = $this->makeCompany();
        $this->profiles->refresh($company);
        $initialVersion = (int) $company->fresh()->profile_version;

        $this->withHeaders(['Authorization' => 'Bearer admin-test-token'])
            ->putJson('/api/admin/companies/'.$company->id, ['sol_user' => 'NUEVOUSUARIO'])
            ->assertOk();

        $fresh = $company->fresh();

        $this->assertSame(1, (int) $fresh->fiscal_revision);
        $this->assertSame($initialVersion + 1, (int) $fresh->profile_version);
        $this->assertSame('NU***O', $this->profiles->snapshotFor($fresh)->solUsernameMasked);
    }

    public function test_fiscal_config_applies_credentials_and_returns_snapshot_with_config_version(): void
    {
        $company = $this->makeCompany(withCertificate: false);
        $payload = $this->fiscalPayload($company, [
            'config_version' => 7,
            'sol_username' => 'NUEVOUSER',
            'idempotency_key' => 'fiscal-key-1',
        ]);

        $response = $this->postJson(
            '/api/internal/company-profile/'.$company->id.'/fiscal-config',
            $payload,
            $this->profileHeaders(self::TENANT_A),
        );

        $response->assertOk();
        $data = $response->json('data');

        $this->assertSame(self::TENANT_A, $data['tenant_id']);
        $this->assertSame($company->id, $data['company_ref']);
        $this->assertSame(7, $data['config_version']);
        $this->assertSame('created', $data['status']);
        $this->assertSame(1, $data['profile_version']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $data['fiscal_digest']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $data['certificate_sha256']);
        $this->assertSame('NU***R', $data['sol_username_masked']);

        $fresh = $company->fresh();

        $this->assertSame('NUEVOUSER', $fresh->sol_user);
        $this->assertSame('greenter', $fresh->sunat_driver);
        $this->assertNotSame($company->certificate_path, $fresh->certificate_path);
        Storage::disk('local')->assertExists($fresh->certificate_path);

        $get = $this->getJson(
            '/api/internal/company-profile/'.$company->id,
            $this->profileHeaders(self::TENANT_A),
        )->assertOk();

        $this->assertSame($data['fiscal_digest'], $get->json('data.fiscal_digest'));
    }

    public function test_fiscal_config_replays_idempotently_without_new_version(): void
    {
        $company = $this->makeCompany(withCertificate: false);
        $payload = $this->fiscalPayload($company, ['idempotency_key' => 'fiscal-key-replay']);

        $this->postJson(
            '/api/internal/company-profile/'.$company->id.'/fiscal-config',
            $payload,
            $this->profileHeaders(self::TENANT_A),
        )->assertOk()->assertJsonPath('data.status', 'created');

        $this->postJson(
            '/api/internal/company-profile/'.$company->id.'/fiscal-config',
            $payload,
            $this->profileHeaders(self::TENANT_A),
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'replayed')
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.profile_version', 1);
    }

    public function test_fiscal_config_rejects_cross_tenant_bootstrap(): void
    {
        $company = $this->makeCompany(withCertificate: false);

        $this->postJson(
            '/api/internal/company-profile/'.$company->id.'/fiscal-config',
            $this->fiscalPayload($company),
            $this->profileHeaders(self::TENANT_A),
        )->assertOk();

        $this->postJson(
            '/api/internal/company-profile/'.$company->id.'/fiscal-config',
            $this->fiscalPayload($company, [
                'tenant_id' => self::TENANT_B,
                'idempotency_key' => 'fiscal-key-other',
                'expected_profile_version' => 1,
            ]),
            $this->profileHeaders(self::TENANT_B),
        )
            ->assertStatus(409)
            ->assertJsonPath('data.status', 'binding_conflict')
            ->assertJsonPath('data.reason', 'company_already_bound_to_other_tenant');
    }

    public function test_fiscal_config_rejects_a_mismatched_company_ruc(): void
    {
        $company = $this->makeCompany(withCertificate: false);
        $before = $company->certificate_path;

        $this->postJson(
            '/api/internal/company-profile/'.$company->id.'/fiscal-config',
            $this->fiscalPayload($company, ['ruc' => '20999999999']),
            $this->profileHeaders(self::TENANT_A),
        )
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'company_ruc_mismatch');

        $this->assertSame($company->sol_user, $company->fresh()->sol_user);
        $this->assertSame($before, $company->fresh()->certificate_path);
    }

    public function test_fiscal_config_rejects_an_invalid_certificate_without_mutation(): void
    {
        $company = $this->makeCompany(withCertificate: false);

        $this->postJson(
            '/api/internal/company-profile/'.$company->id.'/fiscal-config',
            $this->fiscalPayload($company, [
                'certificate_b64' => base64_encode(str_repeat('x', 256)),
            ]),
            $this->profileHeaders(self::TENANT_A),
        )
            ->assertStatus(422)
            ->assertJsonPath('error', 'certificate_rejected');

        $this->assertSame('20111111111', $company->fresh()->ruc);
        $this->assertNull($company->fresh()->certificate_path);
        $this->assertNull($this->profiles->bindingFor($company));
    }

    public function test_fiscal_config_conflicts_on_stale_expected_version(): void
    {
        $company = $this->makeCompany(withCertificate: false);
        $first = $this->fiscalPayload($company, ['idempotency_key' => 'fiscal-key-v1']);

        $this->postJson(
            '/api/internal/company-profile/'.$company->id.'/fiscal-config',
            $first,
            $this->profileHeaders(self::TENANT_A),
        )->assertOk();

        $stale = array_merge($first, [
            'config_version' => 2,
            'sol_username' => 'OTROUSUARIO',
            'expected_profile_version' => 0,
            'idempotency_key' => 'fiscal-key-v2-stale',
        ]);

        $this->postJson(
            '/api/internal/company-profile/'.$company->id.'/fiscal-config',
            $stale,
            $this->profileHeaders(self::TENANT_A),
        )->assertStatus(409)->assertJsonPath('data.status', 'version_conflict');

        $updated = array_merge($stale, [
            'expected_profile_version' => 1,
            'idempotency_key' => 'fiscal-key-v2',
        ]);

        $this->postJson(
            '/api/internal/company-profile/'.$company->id.'/fiscal-config',
            $updated,
            $this->profileHeaders(self::TENANT_A),
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'updated')
            ->assertJsonPath('data.profile_version', 2)
            ->assertJsonPath('data.config_version', 2);
    }

    public function test_reuploading_the_same_certificate_bumps_revision_but_not_the_public_fingerprint(): void
    {
        [$pkcs12, $password] = CompanyCertificateFixture::makePkcs12('synthetic-pass');
        $company = $this->makeCompany(withCertificate: false);

        $company->update([
            'certificate_path' => app(CompanyCertificateStore::class)->store(
                $company,
                UploadedFile::fake()->createWithContent('certificado.p12', $pkcs12),
                $password,
            ),
        ]);

        $this->profiles->refresh($company->fresh());
        $before = $this->profiles->snapshotFor($company->fresh());

        $this->withHeaders(['Authorization' => 'Bearer admin-test-token'])
            ->post('/api/admin/companies/'.$company->id, [
                '_method' => 'PUT',
                'certificate' => UploadedFile::fake()->createWithContent('certificado.p12', $pkcs12),
                'certificate_password' => $password,
            ])
            ->assertOk();

        $fresh = $company->fresh();
        $after = $this->profiles->snapshotFor($fresh);

        $this->assertSame(1, (int) $fresh->fiscal_revision);
        $this->assertSame(2, (int) $fresh->profile_version);
        $this->assertSame($before->certificateSha256, $after->certificateSha256);
        $this->assertNotSame($before->fiscalDigest, $after->fiscalDigest);
    }
}
