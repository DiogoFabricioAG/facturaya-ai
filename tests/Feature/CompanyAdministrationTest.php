<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\CompanyCertificateStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompanyAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_visual_workspaces_are_available(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Entra con la clave de tu empresa.')
            ->assertSee('Actividad de esta empresa')
            ->assertSee('Escribe lo que vendiste')
            ->assertSee('Adjunta un archivo')
            ->assertSee('usa ambas opciones')
            ->assertSee('Los cambios se guardan automáticamente')
            ->assertDontSee('Guardar cambios');

        $this->get('/platform')
            ->assertOk()
            ->assertSee('Gestiona tus empresas emisoras.')
            ->assertSee('Registrar empresa emisora')
            ->assertSee('Archivo .p12 o .pfx')
            ->assertSee('No la guardamos.');
    }

    public function test_admin_can_onboard_a_company_and_secrets_are_encrypted(): void
    {
        Storage::fake('local');
        config()->set('facturaya.platform.admin_token', 'platform-secret');

        $response = $this->post('/api/admin/companies', [
            'ruc' => '20666666666',
            'legal_name' => 'Servicios Seguros S.A.C.',
            'trade_name' => 'Servicios Seguros',
            'ubigeo' => '150101',
            'department' => 'LIMA',
            'province' => 'LIMA',
            'district' => 'MIRAFLORES',
            'address' => 'Av. Seguridad 456',
            'sunat_driver' => 'greenter',
            'sunat_environment' => 'beta',
            'sol_user' => 'FACTURADOR',
            'sol_password' => 'clave-sol-secreta',
            'certificate' => UploadedFile::fake()->createWithContent('certificate.p12', $this->fakePkcs12('cert-secret')),
            'certificate_password' => 'cert-secret',
            'default_series' => 'F001',
            'default_credit_note_series' => 'FC01',
            'token_name' => 'Integración ERP',
        ], [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer platform-secret',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.ruc', '20666666666')
            ->assertJsonPath('data.default_credit_note_series', 'FC01')
            ->assertJsonPath('data.sunat_credentials_configured', true)
            ->assertJsonStructure(['api_token']);

        $company = Company::firstOrFail();
        $raw = DB::table('companies')->where('id', $company->id)->first();

        $this->assertSame('FACTURADOR', $company->sol_user);
        $this->assertSame('clave-sol-secreta', $company->sol_password);
        $this->assertNotSame('FACTURADOR', $raw->sol_user);
        $this->assertNotSame('clave-sol-secreta', $raw->sol_password);

        $encryptedCertificate = Storage::disk('local')->get($company->certificate_path);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $encryptedCertificate);

        $storedCertificate = app(CompanyCertificateStore::class)->read($company);
        $this->assertStringContainsString('BEGIN PRIVATE KEY', $storedCertificate);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $storedCertificate);
        $this->assertStringNotContainsString('cert-secret', $raw->certificate_path);

        $this->withToken($response->json('api_token'))
            ->getJson('/api/company')
            ->assertOk()
            ->assertJsonPath('data.id', $company->id);
    }

    public function test_a_wrong_certificate_password_is_rejected_without_creating_the_company(): void
    {
        Storage::fake('local');
        config()->set('facturaya.platform.admin_token', 'platform-secret');

        $response = $this->post('/api/admin/companies', [
            'ruc' => '20777777777',
            'legal_name' => 'Certificado Incorrecto S.A.C.',
            'ubigeo' => '150101',
            'department' => 'LIMA',
            'province' => 'LIMA',
            'district' => 'LIMA',
            'address' => 'Av. Certificados 123',
            'sunat_driver' => 'greenter',
            'sunat_environment' => 'beta',
            'sol_user' => 'FACTURADOR',
            'sol_password' => 'clave-sol-secreta',
            'certificate' => UploadedFile::fake()->createWithContent('certificate.pfx', $this->fakePkcs12('correct-password')),
            'certificate_password' => 'wrong-password',
            'default_series' => 'F001',
        ], [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer platform-secret',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('certificate_password');

        $this->assertDatabaseMissing('companies', ['ruc' => '20777777777']);
    }

    public function test_admin_routes_reject_an_invalid_platform_token(): void
    {
        config()->set('facturaya.platform.admin_token', 'correct-secret');

        $this->withToken('wrong-secret')
            ->getJson('/api/admin/companies')
            ->assertUnauthorized();
    }

    private function fakePkcs12(string $password): string
    {
        $options = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $bundledConfig = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';

        if (is_file($bundledConfig)) {
            $options['config'] = $bundledConfig;
        }

        $privateKey = openssl_pkey_new($options);

        $csr = openssl_csr_new([
            'countryName' => 'PE',
            'organizationName' => 'FacturaYa Tests',
            'commonName' => '20666666666',
        ], $privateKey, $options);
        $certificate = openssl_csr_sign($csr, null, $privateKey, 365, $options);

        $this->assertNotFalse($certificate);
        $this->assertTrue(openssl_pkcs12_export($certificate, $pkcs12, $privateKey, $password));

        return $pkcs12;
    }
}
