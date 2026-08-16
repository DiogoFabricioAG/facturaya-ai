<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\CompanyCertificateStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
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

    public function test_a_legacy_pkcs12_certificate_can_be_imported_securely(): void
    {
        Storage::fake('local');

        $company = Company::create([
            'ruc' => '20888888888',
            'legal_name' => 'Certificado Legacy S.A.C.',
            'ubigeo' => '150101',
            'department' => 'LIMA',
            'province' => 'LIMA',
            'district' => 'LIMA',
            'address' => 'Av. Certificados 456',
            'sunat_driver' => 'fake',
            'sunat_environment' => 'beta',
            'default_series' => 'F001',
            'default_credit_note_series' => 'FC01',
        ]);
        $password = 'legacy-secret';
        $upload = UploadedFile::fake()->createWithContent('legacy-certificate.p12', $this->fakeLegacyPkcs12($password));

        $path = app(CompanyCertificateStore::class)->store($company, $upload, $password);
        $company->update(['certificate_path' => $path]);

        $encryptedCertificate = Storage::disk('local')->get($path);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $encryptedCertificate);

        $storedCertificate = app(CompanyCertificateStore::class)->read($company);
        $this->assertStringContainsString('BEGIN PRIVATE KEY', $storedCertificate);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $storedCertificate);
    }

    public function test_admin_routes_reject_an_invalid_platform_token(): void
    {
        config()->set('facturaya.platform.admin_token', 'correct-secret');

        $this->withToken('wrong-secret')
            ->getJson('/api/admin/companies')
            ->assertUnauthorized();
    }

    public function test_admin_can_edit_company_configuration_without_replacing_secrets(): void
    {
        config()->set('facturaya.platform.admin_token', 'platform-secret');
        $company = Company::create([
            'ruc' => '20999999999',
            'legal_name' => 'Empresa Original S.A.C.',
            'trade_name' => 'Original',
            'ubigeo' => '150101',
            'department' => 'LIMA',
            'province' => 'LIMA',
            'district' => 'LIMA',
            'address' => 'Av. Inicial 123',
            'sunat_driver' => 'fake',
            'sunat_environment' => 'beta',
            'sol_user' => 'SOLUSER',
            'sol_password' => 'SOLPASS',
            'default_series' => 'F001',
            'default_credit_note_series' => 'FC01',
            'active' => true,
        ]);

        $this->withToken('platform-secret')
            ->putJson('/api/admin/companies/'.$company->id, [
                'legal_name' => 'Empresa Actualizada S.A.C.',
                'sunat_environment' => 'production',
                'default_series' => 'F002',
                'active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.legal_name', 'Empresa Actualizada S.A.C.')
            ->assertJsonPath('data.sunat_environment', 'production')
            ->assertJsonPath('data.default_series', 'F002')
            ->assertJsonPath('data.active', false);

        $this->assertSame('SOLUSER', $company->fresh()->sol_user);
        $this->assertSame('SOLPASS', $company->fresh()->sol_password);
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

    private function fakeLegacyPkcs12(string $password): string
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
            'organizationName' => 'FacturaYa Legacy Tests',
            'commonName' => '20888888888',
        ], $privateKey, $options);
        $certificate = openssl_csr_sign($csr, null, $privateKey, 365, $options);
        $this->assertNotFalse($certificate);
        $this->assertTrue(openssl_pkey_export($privateKey, $privateKeyPem, null, $options));
        $this->assertTrue(openssl_x509_export($certificate, $certificatePem));

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'facturaya-legacy-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory, 0700));
        $keyPath = $directory.DIRECTORY_SEPARATOR.'key.pem';
        $certificatePath = $directory.DIRECTORY_SEPARATOR.'certificate.pem';
        $pkcs12Path = $directory.DIRECTORY_SEPARATOR.'certificate.p12';

        try {
            file_put_contents($keyPath, $privateKeyPem, LOCK_EX);
            file_put_contents($certificatePath, $certificatePem, LOCK_EX);
            @chmod($keyPath, 0600);
            @chmod($certificatePath, 0600);

            $process = new Process([
                'openssl',
                'pkcs12',
                '-export',
                '-legacy',
                '-inkey',
                $keyPath,
                '-in',
                $certificatePath,
                '-out',
                $pkcs12Path,
                '-passout',
                'stdin',
            ]);
            $process->setTimeout(10);
            $process->setInput($password);
            $process->mustRun();

            $contents = file_get_contents($pkcs12Path);
            $this->assertIsString($contents);

            return $contents;
        } finally {
            foreach ([$keyPath, $certificatePath, $pkcs12Path] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
        }
    }
}
