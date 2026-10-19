<?php

namespace Tests\Unit;

use App\Data\CertificateIdentityMatchResult;
use App\Data\FiscalProfileDiagnosticInput;
use App\Services\Sunat\CertificateIdentityMatcher;
use App\Services\Sunat\ConfiguredCertificateIdentityMatcher;
use App\Services\Sunat\FiscalDiagnosticsRuntimeFactory;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

final class FiscalDiagnosticsRuntimeFactoryIdentityTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository([
            'fiscal_diagnostics' => ['consult_enabled' => false],
        ]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function input(): FiscalProfileDiagnosticInput
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'Synthetic factory identity fixture'], $key, ['digest_alg' => 'sha256']);
        self::assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        self::assertNotFalse($certificate);
        self::assertTrue(openssl_x509_export($certificate, $certificatePem));
        self::assertTrue(openssl_pkey_export($key, $keyPem));

        return new FiscalProfileDiagnosticInput(
            profileReference: 'synthetic-factory-profile',
            configVersion: 1,
            environment: FiscalProfileDiagnosticInput::ENVIRONMENT_BETA,
            issuerRuc: '10123456789',
            solUsername: 'SYNTHETIC',
            solPassword: 'synthetic-only-not-a-credential',
            certificatePem: $keyPem.$certificatePem,
        );
    }

    public function test_default_factory_keeps_identity_pending(): void
    {
        $report = (new FiscalDiagnosticsRuntimeFactory)->make()->diagnose($this->input());
        self::assertSame('pending', $report->technicalEvidence->certificate['status']);
        self::assertFalse($report->credentialsTested);
        self::assertFalse($report->caChainVerified);
        self::assertSame([], $report->testedPermissions);
    }

    public function test_injected_server_matcher_receives_public_certificate_only(): void
    {
        $matcher = $this->createMock(CertificateIdentityMatcher::class);
        $matcher->expects(self::once())->method('match')->willReturnCallback(
            function (string $certificatePem, string $expectedRuc): CertificateIdentityMatchResult {
                self::assertStringContainsString('BEGIN CERTIFICATE', $certificatePem);
                self::assertStringNotContainsString('PRIVATE KEY', $certificatePem);
                self::assertSame('10123456789', $expectedRuc);

                return new CertificateIdentityMatchResult(CertificateIdentityMatchResult::STATUS_MATCH);
            },
        );
        $report = (new FiscalDiagnosticsRuntimeFactory($matcher))->make()->diagnose($this->input());
        self::assertSame('verified', $report->technicalEvidence->certificate['status']);
        self::assertSame('server_identity_matcher', $report->technicalEvidence->certificate['source']);
        self::assertFalse($report->credentialsTested);
        self::assertFalse($report->caChainVerified);
        self::assertSame([], $report->testedPermissions);
    }

    public function test_injected_unknown_matcher_never_promotes_identity(): void
    {
        $matcher = $this->createStub(CertificateIdentityMatcher::class);
        $matcher->method('match')->willReturn(
            new CertificateIdentityMatchResult(CertificateIdentityMatchResult::STATUS_UNKNOWN),
        );
        $report = (new FiscalDiagnosticsRuntimeFactory($matcher))->make()->diagnose($this->input());
        self::assertSame('pending', $report->technicalEvidence->certificate['status']);
        self::assertFalse($report->credentialsTested);
        self::assertFalse($report->caChainVerified);
    }

    public function test_consultation_composition_preserves_matcher_without_query(): void
    {
        $previousTimeout = ini_get('default_socket_timeout');
        $matcher = $this->createStub(CertificateIdentityMatcher::class);
        config([
            'fiscal_diagnostics.consult_enabled' => true,
            'fiscal_diagnostics.consult_read_timeout_seconds' => 30,
        ]);
        try {
            self::assertNotFalse(ini_set('default_socket_timeout', '30'));
            $service = (new FiscalDiagnosticsRuntimeFactory($matcher))->make();
            $property = new \ReflectionProperty($service, 'identityMatcher');
            self::assertSame($matcher, $property->getValue($service));
            // Construct from the existing local WSDL only. Never call diagnose
            // or the external probe in this composition acceptance case.
        } finally {
            ini_set('default_socket_timeout', $previousTimeout);
        }
    }

    private function emptyIdentityConfiguration(): array
    {
        return [
            'issuer_certificate_der_base64' => null,
            'issuer_sha256_der' => null,
            'profile_id' => null,
            'policy_oid' => null,
            'ruc_attribute_oid' => null,
            'ruc_attribute_prefix' => '',
        ];
    }

    public function test_empty_server_configuration_keeps_matcher_absent(): void
    {
        config(['fiscal_diagnostics.identity_profile' => $this->emptyIdentityConfiguration()]);
        $service = (new FiscalDiagnosticsRuntimeFactory)->make();
        self::assertNull((new \ReflectionProperty($service, 'identityMatcher'))->getValue($service));
    }

    public function test_partial_server_configuration_is_opaque_and_never_falls_back(): void
    {
        $configuration = $this->emptyIdentityConfiguration();
        $configuration['profile_id'] = 'synthetic-partial-profile';
        config(['fiscal_diagnostics.identity_profile' => $configuration]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fiscal_diagnostics_identity_configuration_invalid');
        (new FiscalDiagnosticsRuntimeFactory)->make();
    }

    public function test_invalid_public_der_configuration_is_rejected(): void
    {
        config(['fiscal_diagnostics.identity_profile' => [
            'issuer_certificate_der_base64' => 'synthetic-invalid-base64!',
            'issuer_sha256_der' => str_repeat('0', 64),
            'profile_id' => 'synthetic-profile',
            'policy_oid' => '1.2.3.4.5',
            'ruc_attribute_oid' => '1.2.3.4.6',
            'ruc_attribute_prefix' => '',
        ]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fiscal_diagnostics_identity_configuration_invalid');
        (new FiscalDiagnosticsRuntimeFactory)->make();
    }

    public function test_public_pinned_server_metadata_builds_real_matcher(): void
    {
        $fixture = $this->input();
        $certificate = openssl_x509_read($fixture->certificatePem());
        self::assertNotFalse($certificate);
        self::assertTrue(openssl_x509_export($certificate, $publicPem));
        $encoded = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $publicPem);
        $der = base64_decode($encoded, true);
        self::assertIsString($der);
        config(['fiscal_diagnostics.identity_profile' => [
            'issuer_certificate_der_base64' => $encoded,
            'issuer_sha256_der' => hash('sha256', $der),
            'profile_id' => 'synthetic-profile',
            'policy_oid' => '1.2.3.4.5',
            'ruc_attribute_oid' => '1.2.3.4.6',
            'ruc_attribute_prefix' => '',
        ]]);
        $service = (new FiscalDiagnosticsRuntimeFactory)->make();
        $matcher = (new \ReflectionProperty($service, 'identityMatcher'))->getValue($service);
        self::assertInstanceOf(ConfiguredCertificateIdentityMatcher::class, $matcher);
        self::assertSame(CertificateIdentityMatchResult::STATUS_UNKNOWN,
            $matcher->match($publicPem, '10123456789')->status);
    }
}
