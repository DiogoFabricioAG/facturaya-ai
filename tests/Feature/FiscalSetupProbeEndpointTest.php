<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Contrato HTTP del diagnóstico fiscal: el envelope legacy se conserva y el
 * objeto aditivo `technical_evidence` (schema_version 1) se expone al nivel
 * superior sin declarar readiness ni aceptación SUNAT.
 */
final class FiscalSetupProbeEndpointTest extends TestCase
{
    private const TOKEN = 'fiscal-diagnostics-test-token';

    private const TENANT = 'c1f2e3d4-b5a6-4789-8012-3456789abcde';

    private const RUC = '20123456789';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fiscal_diagnostics.enabled' => true,
            'fiscal_diagnostics.allow_local_http' => true,
            'fiscal_diagnostics.bridge_token' => self::TOKEN,
            'fiscal_diagnostics.consult_enabled' => false,
        ]);
    }

    /**
     * @return array{0: string, 1: string} contenido binario PKCS#12 y contraseña
     */
    private function pkcs12(string $ruc): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key);

        $csr = openssl_csr_new(
            [
                'commonName' => 'meowlab-evidence-synthetic',
                'organizationalUnitName' => 'RUC-'.$ruc,
            ],
            $key,
            ['digest_alg' => 'sha256'],
        );
        self::assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256']);
        self::assertNotFalse($certificate);

        $certificatePem = '';
        openssl_x509_export($certificate, $certificatePem);
        $keyPem = '';
        openssl_pkey_export($key, $keyPem);

        $pkcs12 = '';
        self::assertTrue(openssl_pkcs12_export($certificatePem, $pkcs12, $keyPem, 'synthetic-passphrase'));

        return [$pkcs12, 'synthetic-passphrase'];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $ruc = self::RUC, ?string $certificateRuc = null): array
    {
        [$pkcs12, $passphrase] = $this->pkcs12($certificateRuc ?? $ruc);

        return [
            'tenant_id' => self::TENANT,
            'config_version' => 3,
            'environment' => 'production',
            'sol_secondary_user_ref' => 'sec-user-11111111-2222-3333-4444-555555555555',
            'certificate_ref' => 'cert-66666666-7777-8888-9999-000000000000',
            'ruc' => $ruc,
            'credentials' => [
                'sol_username' => 'MODDATOS',
                'sol_password' => 'synthetic-secret',
            ],
            'certificate' => [
                'certificate_b64' => base64_encode($pkcs12),
                'passphrase' => $passphrase,
            ],
            'required_permissions' => [
                'contingency',
                'electronic_invoice',
                'see_sol',
                'see_taxpayer_submission',
                'tax_digital_certificate',
                'validity_query',
            ],
            'requested_at' => '2026-10-09T12:00:00+00:00',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function probe(array $payload)
    {
        return $this->postJson(
            'http://127.0.0.1/api/fiscal-setup/probe',
            $payload,
            ['Authorization' => 'Bearer '.self::TOKEN],
        );
    }

    public function test_endpoint_keeps_legacy_envelope_and_adds_technical_evidence(): void
    {
        $response = $this->probe($this->payload());

        $response->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('tenant_id', self::TENANT)
            ->assertJsonPath('config_version', 3)
            ->assertJsonPath('environment', 'production')
            ->assertJsonPath('ruc', self::RUC)
            ->assertJsonPath('technical_evidence.schema_version', 1)
            ->assertJsonPath('technical_evidence.source', 'facturaya_non_issuing_diagnostics')
            ->assertJsonPath('technical_evidence.credentials.status', 'pending')
            ->assertJsonPath('technical_evidence.credentials.source', 'pending_dashboard_execution')
            ->assertJsonPath('technical_evidence.credentials.environment', 'production')
            ->assertJsonPath('technical_evidence.certificate.status', 'pending')
            ->assertJsonPath('technical_evidence.certificate.source', 'local_certificate_material')
            ->assertJsonPath('technical_evidence.certificate.reason', 'certificate_issuer_identity_not_demonstrated')
            ->assertJsonPath('technical_evidence.signature.status', 'verified')
            ->assertJsonPath('technical_evidence.signature.source', 'greenter_local_xml_signature')
            ->assertJsonPath('technical_evidence.trust.status', 'pending')
            ->assertJsonPath('technical_evidence.trust.source', 'infrastructure_trust_bundle_required');

        $body = $response->getContent();
        self::assertIsString($body);
        self::assertStringNotContainsString('synthetic-secret', $body);
        self::assertStringNotContainsString('synthetic-passphrase', $body);
        self::assertStringNotContainsString('PRIVATE KEY', $body);

        $legacy = $response->json('diagnostics');
        self::assertIsArray($legacy);
        self::assertSame(1, $legacy['contract_version']);
        self::assertArrayNotHasKey('technical_evidence', $legacy);
        self::assertSame([], $legacy['external']['tested_permissions']);
        self::assertFalse($legacy['external']['certificate_tested']);
        self::assertFalse($legacy['external']['signature_tested']);
        self::assertFalse($legacy['external']['ca_chain_verified']);
        self::assertNull($legacy['external']['probe_valid_until']);

        $checksByName = [];
        foreach ($legacy['checks'] as $check) {
            $checksByName[$check['check']] = $check;
        }

        self::assertSame('verified', $checksByName['greenter_local_xml_signature']['status']);
        self::assertSame('greenter_local_xml_signature', $checksByName['greenter_local_xml_signature']['source']);
        self::assertSame('verified', $checksByName['local_signature_sha1']['status']);
        self::assertSame('pending', $checksByName['signature_xmlsig_sunat']['status']);
    }

    public function test_invalid_local_response_reports_rejected_evidence_without_secrets(): void
    {
        $payload = $this->payload();
        $payload['certificate']['certificate_b64'] = base64_encode(random_bytes(256));

        $response = $this->probe($payload);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'invalid_local')
            ->assertJsonPath('technical_evidence.certificate.status', 'rejected')
            ->assertJsonPath('technical_evidence.certificate.source', 'local_certificate_material')
            ->assertJsonPath('technical_evidence.signature.status', 'pending')
            ->assertJsonPath('technical_evidence.credentials.status', 'pending');

        $body = $response->getContent();
        self::assertIsString($body);
        self::assertStringNotContainsString('synthetic-secret', $body);
    }

    public function test_generic_ruc_marker_does_not_authorize_identity_without_matcher(): void
    {
        $response = $this->probe($this->payload(self::RUC, '20999999999'));

        $response->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('technical_evidence.certificate.status', 'pending')
            ->assertJsonPath('technical_evidence.certificate.reason', 'certificate_issuer_identity_not_demonstrated')
            ->assertJsonPath('technical_evidence.signature.status', 'verified')
            ->assertJsonPath('technical_evidence.signature.source', 'greenter_local_xml_signature');
    }
}
