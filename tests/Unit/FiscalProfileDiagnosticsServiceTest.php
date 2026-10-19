<?php

namespace Tests\Unit;

use App\Data\CertificateIdentityMatchResult;
use App\Data\FiscalExternalProbeOutcome;
use App\Data\FiscalProfileDiagnosticInput;
use App\Data\LocalXmlSignatureResult;
use App\Services\Sunat\CertificateIdentityMatcher;
use App\Services\Sunat\FiscalProfileDiagnosticsService;
use App\Services\Sunat\LocalXmlSignatureProbe;
use App\Services\Sunat\SunatNonIssuingProbe;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class FiscalProfileDiagnosticsServiceTest extends TestCase
{
    private function certificatePem(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key);

        $csr = openssl_csr_new(['commonName' => 'MeowLab Diagnostics Test'], $key, ['digest_alg' => 'sha256']);
        self::assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256']);
        self::assertNotFalse($certificate);

        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $keyPem);

        return $keyPem.$certificatePem;
    }

    private function input(): FiscalProfileDiagnosticInput
    {
        return new FiscalProfileDiagnosticInput(
            profileReference: 'tenant-ref-diagnostics',
            configVersion: 2,
            environment: FiscalProfileDiagnosticInput::ENVIRONMENT_PRODUCTION,
            issuerRuc: '20123456789',
            solUsername: 'USUARIO1',
            solPassword: 'synthetic-sol-secret',
            certificatePem: $this->certificatePem(),
        );
    }

    private function certificatePemWithRuc(string $ruc): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key);

        $csr = openssl_csr_new(
            [
                'commonName' => 'MeowLab Diagnostics Test',
                'organizationalUnitName' => 'RUC-'.$ruc,
            ],
            $key,
            ['digest_alg' => 'sha256'],
        );
        self::assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256']);
        self::assertNotFalse($certificate);

        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $keyPem);

        return $keyPem.$certificatePem;
    }

    private function inputWithPem(string $pem, string $ruc = '20123456789'): FiscalProfileDiagnosticInput
    {
        return new FiscalProfileDiagnosticInput(
            profileReference: 'tenant-ref-diagnostics',
            configVersion: 2,
            environment: FiscalProfileDiagnosticInput::ENVIRONMENT_PRODUCTION,
            issuerRuc: $ruc,
            solUsername: 'USUARIO1',
            solPassword: 'synthetic-sol-secret',
            certificatePem: $pem,
        );
    }

    private function probeReturning(FiscalExternalProbeOutcome $outcome): SunatNonIssuingProbe
    {
        return new class($outcome) implements SunatNonIssuingProbe
        {
            public function __construct(private readonly FiscalExternalProbeOutcome $outcome) {}

            public function probe(FiscalProfileDiagnosticInput $input): FiscalExternalProbeOutcome
            {
                return $this->outcome;
            }
        };
    }

    private function identityMatcherReturning(
        CertificateIdentityMatchResult $result,
        ?object $capture = null,
    ): CertificateIdentityMatcher {
        return new class($result, $capture) implements CertificateIdentityMatcher
        {
            public function __construct(
                private readonly CertificateIdentityMatchResult $result,
                private readonly ?object $capture,
            ) {}

            public function match(string $certificatePem, string $expectedRuc): CertificateIdentityMatchResult
            {
                if ($this->capture !== null) {
                    $this->capture->calls = [
                        'certificate_pem' => $certificatePem,
                        'expected_ruc' => $expectedRuc,
                    ];
                }

                return $this->result;
            }
        };
    }

    private function xmlSignatureProbeReturning(
        LocalXmlSignatureResult|Throwable $result,
        ?object $capture = null,
    ): LocalXmlSignatureProbe {
        return new class($result, $capture) implements LocalXmlSignatureProbe
        {
            public function __construct(
                private readonly LocalXmlSignatureResult|Throwable $result,
                private readonly ?object $capture,
            ) {}

            public function sign(string $combinedPem): string
            {
                throw new RuntimeException('sign_not_used_in_service_tests');
            }

            public function verify(string $certificatePem, string $signedXml): bool
            {
                return false;
            }

            public function signAndVerify(string $combinedPem): LocalXmlSignatureResult
            {
                if ($this->capture !== null) {
                    $this->capture->combined_pem = $combinedPem;
                }

                if ($this->result instanceof Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        };
    }

    private function outcome(string $outcome, bool $trusted): FiscalExternalProbeOutcome
    {
        return new FiscalExternalProbeOutcome(
            outcome: $outcome,
            transportTrusted: $trusted,
            endpointHost: 'www.sunat.gob.pe',
            sunatCode: '1033',
            sunatMessage: 'El comprobante no existe',
            occurredAt: '2026-10-08T12:00:00+00:00',
        );
    }

    /**
     * @param  list<array{check: string, status: string, source: string, reason: ?string}>  $checks
     * @return array{status: string, reason: ?string}|null
     */
    private function findCheck(array $checks, string $name): ?array
    {
        foreach ($checks as $check) {
            if ($check['check'] === $name) {
                return ['status' => $check['status'], 'reason' => $check['reason']];
            }
        }

        return null;
    }

    public function test_accepted_credentials_with_trusted_consult_are_verified(): void
    {
        $service = new FiscalProfileDiagnosticsService(
            $this->probeReturning($this->outcome(FiscalExternalProbeOutcome::OUTCOME_AUTHENTICATION_ACCEPTED, true)),
        );

        $report = $service->diagnose($this->input());

        self::assertTrue($report->credentialsTested);
        self::assertTrue($report->localSignatureVerified);
        self::assertFalse($report->certificateTested);
        self::assertFalse($report->caChainVerified);
        self::assertFalse($report->revocationVerified);
        self::assertNull($report->probeValidUntil);

        $credentials = $this->findCheck($report->checks, 'ruc_sol_credentials');
        self::assertSame(['status' => 'verified', 'reason' => null], $credentials);

        foreach ([
            'requires_signed_document_submission',
            'requires_signed_submission_validation',
            'requires_validated_trust_anchor',
            'requires_crl_ocsp_service',
            'not_proven_by_non_issuing_consult',
            'requires_authorized_submission',
        ] as $reason) {
            self::assertContains($reason, $report->pendingReasons);
        }
    }

    public function test_technical_evidence_separates_local_signature_from_sunat_acceptance(): void
    {
        $service = new FiscalProfileDiagnosticsService(
            $this->probeReturning($this->outcome(FiscalExternalProbeOutcome::OUTCOME_AUTHENTICATION_ACCEPTED, true)),
        );

        $report = $service->diagnose($this->input());
        $evidence = $report->technicalEvidence;
        self::assertNotNull($evidence);

        $blocks = $evidence->toArray();

        self::assertSame(2, $blocks['schema_version']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $blocks['certificate_der_sha256']);
        self::assertSame('facturaya_non_issuing_diagnostics', $blocks['source']);

        self::assertSame('verified', $blocks['credentials']['status']);
        self::assertSame('sunat_billconsult_getstatuscdr', $blocks['credentials']['source']);
        self::assertSame('production', $blocks['credentials']['environment']);
        self::assertNull($blocks['credentials']['reason']);

        self::assertSame('pending', $blocks['certificate']['status']);
        self::assertSame('local_certificate_material', $blocks['certificate']['source']);
        self::assertSame('certificate_issuer_identity_not_demonstrated', $blocks['certificate']['reason']);

        self::assertSame('verified', $blocks['signature']['status']);
        self::assertSame('local_engine_signature', $blocks['signature']['source']);
        self::assertNull($blocks['signature']['reason']);

        self::assertSame('pending', $blocks['trust']['status']);
        self::assertSame('infrastructure_trust_bundle_required', $blocks['trust']['source']);
        self::assertSame('requires_validated_trust_anchor', $blocks['trust']['reason']);

        self::assertArrayNotHasKey('tested_permissions', $blocks);
        self::assertSame([], $report->testedPermissions);

        $encoded = json_encode($blocks, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('synthetic-sol-secret', $encoded);
        self::assertStringNotContainsString('PRIVATE KEY', $encoded);
    }

    public function test_technical_evidence_keeps_certificate_pending_without_trusted_matcher_even_with_ruc_marker(): void
    {
        $report = (new FiscalProfileDiagnosticsService)->diagnose(
            $this->inputWithPem($this->certificatePemWithRuc('20123456789')),
        );

        $blocks = $report->technicalEvidence?->toArray();
        self::assertIsArray($blocks);

        self::assertSame('pending', $blocks['certificate']['status']);
        self::assertSame('local_certificate_material', $blocks['certificate']['source']);
        self::assertSame('certificate_issuer_identity_not_demonstrated', $blocks['certificate']['reason']);
        self::assertSame('verified', $blocks['signature']['status']);
    }

    public function test_technical_evidence_verifies_certificate_only_with_injected_matcher_match(): void
    {
        $capture = new class
        {
            public array $calls = [];
        };

        $service = new FiscalProfileDiagnosticsService(
            identityMatcher: $this->identityMatcherReturning(
                new CertificateIdentityMatchResult(CertificateIdentityMatchResult::STATUS_MATCH),
                $capture,
            ),
        );

        $report = $service->diagnose($this->inputWithPem($this->certificatePemWithRuc('20123456789')));
        $blocks = $report->technicalEvidence?->toArray();
        self::assertIsArray($blocks);

        self::assertSame('verified', $blocks['certificate']['status']);
        self::assertSame('server_identity_matcher', $blocks['certificate']['source']);
        self::assertNull($blocks['certificate']['reason']);

        self::assertSame('20123456789', $capture->calls['expected_ruc']);
        self::assertStringContainsString('BEGIN CERTIFICATE', $capture->calls['certificate_pem']);
        self::assertStringNotContainsString('PRIVATE KEY', $capture->calls['certificate_pem']);

        self::assertFalse($report->certificateTested);
        self::assertSame([], $report->testedPermissions);
    }

    public function test_technical_evidence_maps_unknown_and_ambiguous_identity_to_pending(): void
    {
        foreach ([
            [CertificateIdentityMatchResult::STATUS_UNKNOWN, 'certificate_issuer_identity_not_demonstrated'],
            [CertificateIdentityMatchResult::STATUS_AMBIGUOUS, 'certificate_issuer_identity_ambiguous'],
        ] as [$status, $reason]) {
            $service = new FiscalProfileDiagnosticsService(
                identityMatcher: $this->identityMatcherReturning(new CertificateIdentityMatchResult($status)),
            );

            $blocks = $service->diagnose($this->input())->technicalEvidence?->toArray();
            self::assertIsArray($blocks);

            self::assertSame('pending', $blocks['certificate']['status'], $status);
            self::assertSame('server_identity_matcher', $blocks['certificate']['source'], $status);
            self::assertSame($reason, $blocks['certificate']['reason'], $status);
        }
    }

    public function test_technical_evidence_rejects_conflicting_identity_only_from_trusted_matcher(): void
    {
        $service = new FiscalProfileDiagnosticsService(
            identityMatcher: $this->identityMatcherReturning(
                new CertificateIdentityMatchResult(CertificateIdentityMatchResult::STATUS_CONFLICT),
            ),
        );

        $blocks = $service->diagnose($this->input())->technicalEvidence?->toArray();
        self::assertIsArray($blocks);

        self::assertSame('rejected', $blocks['certificate']['status']);
        self::assertSame('server_identity_matcher', $blocks['certificate']['source']);
        self::assertSame('certificate_issuer_identity_conflict', $blocks['certificate']['reason']);
        self::assertSame('verified', $blocks['signature']['status']);
    }

    public function test_signature_evidence_prefers_greenter_xml_source_when_engine_verifies(): void
    {
        $capture = new class
        {
            public ?string $combined_pem = null;
        };

        $service = new FiscalProfileDiagnosticsService(
            xmlSignatureProbe: $this->xmlSignatureProbeReturning(
                new LocalXmlSignatureResult(LocalXmlSignatureResult::STATUS_VERIFIED),
                $capture,
            ),
        );

        $report = $service->diagnose($this->input());
        $blocks = $report->technicalEvidence?->toArray();
        self::assertIsArray($blocks);

        self::assertSame('verified', $blocks['signature']['status']);
        self::assertSame('greenter_local_xml_signature', $blocks['signature']['source']);
        self::assertNull($blocks['signature']['reason']);

        self::assertIsString($capture->combined_pem);
        self::assertStringContainsString('PRIVATE KEY', $capture->combined_pem);
        self::assertStringContainsString('BEGIN CERTIFICATE', $capture->combined_pem);

        self::assertSame(['status' => 'verified', 'reason' => null], $this->findCheck($report->checks, 'greenter_local_xml_signature'));
        self::assertSame(['status' => 'verified', 'reason' => null], $this->findCheck($report->checks, 'local_signature_sha1'));

        self::assertSame('pending', $blocks['credentials']['status']);
        self::assertSame('pending', $blocks['certificate']['status']);
        self::assertSame('pending', $blocks['trust']['status']);
        self::assertFalse($report->signatureTested);
        self::assertFalse($report->certificateTested);
        self::assertSame([], $report->testedPermissions);
    }

    public function test_signature_evidence_maps_engine_verification_failure_to_rejected(): void
    {
        $service = new FiscalProfileDiagnosticsService(
            xmlSignatureProbe: $this->xmlSignatureProbeReturning(
                new LocalXmlSignatureResult(LocalXmlSignatureResult::STATUS_FAILED),
            ),
        );

        $report = $service->diagnose($this->input());
        $blocks = $report->technicalEvidence?->toArray();
        self::assertIsArray($blocks);

        self::assertSame('rejected', $blocks['signature']['status']);
        self::assertSame('greenter_local_xml_signature', $blocks['signature']['source']);
        self::assertSame('greenter_local_xml_signature_verification_failed', $blocks['signature']['reason']);
        self::assertSame(
            ['status' => 'failed', 'reason' => 'greenter_local_xml_signature_verification_failed'],
            $this->findCheck($report->checks, 'greenter_local_xml_signature'),
        );
    }

    public function test_signature_evidence_maps_engine_exception_to_pending(): void
    {
        $service = new FiscalProfileDiagnosticsService(
            xmlSignatureProbe: $this->xmlSignatureProbeReturning(new RuntimeException('engine_down')),
        );

        $report = $service->diagnose($this->input());
        $blocks = $report->technicalEvidence?->toArray();
        self::assertIsArray($blocks);

        self::assertSame('pending', $blocks['signature']['status']);
        self::assertSame('greenter_local_xml_signature', $blocks['signature']['source']);
        self::assertSame('greenter_local_xml_signature_engine_unavailable', $blocks['signature']['reason']);
        self::assertContains('greenter_local_xml_signature_engine_unavailable', $report->pendingReasons);
    }

    public function test_technical_evidence_keeps_credentials_pending_without_probe(): void
    {
        $report = (new FiscalProfileDiagnosticsService)->diagnose($this->input());

        $evidence = $report->technicalEvidence;
        self::assertNotNull($evidence);
        $blocks = $evidence->toArray();

        self::assertSame('pending', $blocks['credentials']['status']);
        self::assertSame('pending_dashboard_execution', $blocks['credentials']['source']);
        self::assertSame('external_probe_not_configured', $blocks['credentials']['reason']);
    }

    public function test_technical_evidence_rejects_credentials_when_sunat_rejects_authentication(): void
    {
        $service = new FiscalProfileDiagnosticsService(
            $this->probeReturning($this->outcome(FiscalExternalProbeOutcome::OUTCOME_AUTHENTICATION_REJECTED, true)),
        );

        $evidence = $service->diagnose($this->input())->technicalEvidence;
        self::assertNotNull($evidence);
        $blocks = $evidence->toArray();

        self::assertSame('rejected', $blocks['credentials']['status']);
        self::assertSame('sunat_authentication_rejected', $blocks['credentials']['reason']);
        self::assertSame('pending', $blocks['certificate']['status']);
        self::assertSame('verified', $blocks['signature']['status']);
    }

    public function test_rejected_credentials_fail_the_check_without_secrets(): void
    {
        $service = new FiscalProfileDiagnosticsService(
            $this->probeReturning(new FiscalExternalProbeOutcome(
                outcome: FiscalExternalProbeOutcome::OUTCOME_AUTHENTICATION_REJECTED,
                transportTrusted: true,
                endpointHost: 'www.sunat.gob.pe',
                sunatCode: '2335',
                sunatMessage: 'Usuario o contraseña incorrectos',
                occurredAt: '2026-10-08T12:00:00+00:00',
            )),
        );

        $report = $service->diagnose($this->input());

        self::assertFalse($report->credentialsTested);
        $credentials = $this->findCheck($report->checks, 'ruc_sol_credentials');
        self::assertSame('failed', $credentials['status']);
        self::assertSame('sunat_authentication_rejected', $credentials['reason']);
        self::assertNotContains('sunat_authentication_rejected', $report->pendingReasons);

        $encoded = json_encode($report->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('synthetic-sol-secret', $encoded);
        self::assertStringNotContainsString('PRIVATE KEY', $encoded);
    }

    public function test_without_probe_every_external_check_stays_pending(): void
    {
        $service = new FiscalProfileDiagnosticsService;

        $report = $service->diagnose($this->input());

        self::assertFalse($report->credentialsTested);
        self::assertContains('external_probe_not_configured', $report->pendingReasons);

        $credentials = $this->findCheck($report->checks, 'ruc_sol_credentials');
        self::assertSame('pending', $credentials['status']);
        self::assertSame('external_probe_not_configured', $credentials['reason']);
    }

    public function test_accepted_credentials_without_trusted_transport_stay_pending(): void
    {
        $service = new FiscalProfileDiagnosticsService(
            $this->probeReturning($this->outcome(FiscalExternalProbeOutcome::OUTCOME_AUTHENTICATION_ACCEPTED, false)),
        );

        $report = $service->diagnose($this->input());

        self::assertFalse($report->credentialsTested);
        $credentials = $this->findCheck($report->checks, 'ruc_sol_credentials');
        self::assertSame('pending', $credentials['status']);
        self::assertSame('external_transport_not_verified', $credentials['reason']);
    }

    public function test_costly_checks_are_always_explicitly_pending(): void
    {
        $service = new FiscalProfileDiagnosticsService;

        $report = $service->diagnose($this->input());

        foreach (['certificate_sunat_acceptance', 'signature_xmlsig_sunat', 'ca_chain_trust', 'revocation_status', 'emission_permissions', 'sunat_acceptance'] as $name) {
            $check = $this->findCheck($report->checks, $name);
            self::assertNotNull($check, $name);
            self::assertSame('pending', $check['status'], $name);
            self::assertNotNull($check['reason'], $name);
        }
    }
}
