<?php

namespace Tests\Feature;

use App\Data\CompanyProfileSnapshot;
use App\Models\Company;
use App\Services\CompanyCertificateStore;
use App\Services\CompanyProfileSyncService;
use App\Services\CompanyProfileVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\CompanyCertificateFixture;
use Tests\TestCase;

final class CompanyCertificateRotationTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 'c1f2e3d4-b5a6-4789-8012-3456789abcde';

    private const PROFILE_TOKEN = 'profile-bridge-test-token';

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
    }

    private function makeCompany(): Company
    {
        $company = Company::create([
            'ruc' => '20111111111',
            'legal_name' => 'Empresa de rotación',
            'ubigeo' => '150101',
            'department' => 'LIMA',
            'province' => 'LIMA',
            'district' => 'LIMA',
            'address' => 'Av. Rotación 123',
            'sunat_driver' => 'greenter',
            'sunat_environment' => 'production',
            'sol_user' => 'MODDATOS',
            'sol_password' => 'synthetic-sol-secret',
            'default_series' => 'F001',
            'default_boleta_series' => 'B001',
            'active' => true,
        ]);

        $company->update(['certificate_path' => CompanyCertificateFixture::storeFor($company)]);

        return $company->fresh();
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        return ['Authorization' => 'Bearer admin-test-token'];
    }

    public function test_store_uses_a_new_immutable_path_and_never_overwrites_the_active_certificate(): void
    {
        $company = $this->makeCompany();
        $oldPath = (string) $company->certificate_path;
        $oldBytes = Storage::disk('local')->get($oldPath);

        [$pkcs12, $password] = CompanyCertificateFixture::makePkcs12('rotation-pass');

        $newPath = app(CompanyCertificateStore::class)->store(
            $company,
            UploadedFile::fake()->createWithContent('certificado.p12', $pkcs12),
            $password,
        );

        $this->assertNotSame($oldPath, $newPath);
        $this->assertTrue(Storage::disk('local')->exists($oldPath));
        $this->assertSame($oldBytes, Storage::disk('local')->get($oldPath));
        $this->assertTrue(Storage::disk('local')->exists($newPath));
    }

    public function test_admin_update_with_db_failure_preserves_active_certificate_and_cleans_the_new_file(): void
    {
        $company = $this->makeCompany();
        $oldPath = (string) $company->certificate_path;
        $oldBytes = Storage::disk('local')->get($oldPath);

        $this->app->instance(CompanyProfileSyncService::class, $this->failingService());

        [$pkcs12, $password] = CompanyCertificateFixture::makePkcs12('failure-pass');

        $this->withHeaders($this->adminHeaders())
            ->post('/api/admin/companies/'.$company->id, [
                '_method' => 'PUT',
                'certificate' => UploadedFile::fake()->createWithContent('certificado.p12', $pkcs12),
                'certificate_password' => $password,
            ])
            ->assertStatus(500);

        $fresh = $company->fresh();

        $this->assertSame($oldPath, $fresh->certificate_path);
        $this->assertSame($oldBytes, Storage::disk('local')->get($oldPath));
        $this->assertSame(0, (int) $fresh->fiscal_revision);
        $this->assertSame(
            [$oldPath],
            Storage::disk('local')->allFiles('company-certificates/'.$company->id),
        );
    }

    public function test_admin_update_with_an_invalid_certificate_does_not_mutate_or_write(): void
    {
        $company = $this->makeCompany();
        $oldPath = (string) $company->certificate_path;
        $oldBytes = Storage::disk('local')->get($oldPath);

        $this->withHeaders($this->adminHeaders())
            ->post('/api/admin/companies/'.$company->id, [
                '_method' => 'PUT',
                'certificate' => UploadedFile::fake()->createWithContent(
                    'certificado.p12',
                    str_repeat('x', 256),
                ),
                'certificate_password' => 'cualquiera',
            ])
            ->assertStatus(422);

        $fresh = $company->fresh();

        $this->assertSame($oldPath, $fresh->certificate_path);
        $this->assertSame($oldBytes, Storage::disk('local')->get($oldPath));
        $this->assertSame('MODDATOS', $fresh->sol_user);
        $this->assertSame(
            [$oldPath],
            Storage::disk('local')->allFiles('company-certificates/'.$company->id),
        );
    }

    public function test_fiscal_config_db_failure_preserves_active_certificate_and_cleans_the_new_file(): void
    {
        $company = $this->makeCompany();
        $oldPath = (string) $company->certificate_path;
        $oldBytes = Storage::disk('local')->get($oldPath);

        $this->app->instance(CompanyProfileSyncService::class, $this->failingService());

        [$pkcs12, $password] = CompanyCertificateFixture::makePkcs12('fiscal-failure-pass');

        $this->postJson(
            '/api/internal/company-profile/'.$company->id.'/fiscal-config',
            [
                'tenant_id' => self::TENANT_A,
                'config_version' => 3,
                'ruc' => '20111111111',
                'sol_username' => 'NUEVOUSER',
                'sol_password' => 'nueva-clave-sintetica',
                'certificate_b64' => base64_encode($pkcs12),
                'certificate_passphrase' => $password,
                'expected_profile_version' => 0,
                'idempotency_key' => 'fiscal-failure-key',
                'environment' => 'production',
            ],
            [
                'Authorization' => 'Bearer '.self::PROFILE_TOKEN,
                'X-MeowLab-Tenant-ID' => self::TENANT_A,
            ],
        )->assertStatus(503);

        $fresh = $company->fresh();

        $this->assertSame('MODDATOS', $fresh->sol_user);
        $this->assertSame($oldPath, $fresh->certificate_path);
        $this->assertSame($oldBytes, Storage::disk('local')->get($oldPath));
        $this->assertSame(0, (int) $fresh->fiscal_revision);
        $this->assertSame(
            [$oldPath],
            Storage::disk('local')->allFiles('company-certificates/'.$company->id),
        );
    }

    private function failingService(): CompanyProfileSyncService
    {
        return new class(app(CompanyCertificateStore::class), app(CompanyProfileVersionService::class)) extends CompanyProfileSyncService
        {
            public function refresh(Company $company): ?CompanyProfileSnapshot
            {
                parent::refresh($company);

                throw new RuntimeException('forced_db_failure_after_write');
            }
        };
    }
}
