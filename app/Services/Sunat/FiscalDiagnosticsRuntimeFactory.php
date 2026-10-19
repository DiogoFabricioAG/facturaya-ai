<?php

namespace App\Services\Sunat;

use Greenter\Ws\Services\WsdlProvider;
use RuntimeException;

/**
 * Composición del servicio de diagnóstico, sin E/S en boot.
 *
 * El motor local de firma XMLDSig de Greenter (`GreenterLocalXmlSignatureProbe`)
 * se inyecta siempre: firma y verifica un XML diagnóstico no tributario en
 * memoria, sin SOAP ni red, y su evidencia lleva
 * `source: greenter_local_xml_signature`. Con `consult_enabled` apagado
 * (default) devuelve el diagnóstico local puro y no construye ningún cliente
 * SOAP. Solo cuando el flag está encendido resuelve el cliente SOAP de consulta
 * con TLS verificado por el servidor, CA y WSDL locales; si falta configuración
 * falla de forma cerrada en la request (el endpoint responde 503 opaco), nunca
 * con un cliente inseguro.
 *
 * `transportTrusted` es verdadero únicamente porque el cliente lo construye
 * este factory controlado por servidor; no se acepta desde la request.
 */
final class FiscalDiagnosticsRuntimeFactory
{
    public function __construct(
        private readonly ?CertificateIdentityMatcher $identityMatcher = null,
    ) {}

    public function make(): FiscalProfileDiagnosticsService
    {
        $identityMatcher = $this->identityMatcher ?? $this->configuredIdentityMatcher();
        $xmlSignatureProbe = new GreenterLocalXmlSignatureProbe;

        if (config('fiscal_diagnostics.consult_enabled') !== true) {
            return new FiscalProfileDiagnosticsService(
                identityMatcher: $identityMatcher,
                xmlSignatureProbe: $xmlSignatureProbe,
            );
        }

        $caBundle = config('fiscal_diagnostics.server_ca_bundle');

        if (is_string($caBundle) && $caBundle !== '' && (! is_file($caBundle) || ! is_readable($caBundle))) {
            throw new RuntimeException('fiscal_diagnostics_consult_ca_unavailable');
        }

        $caBundlePath = is_string($caBundle) && $caBundle !== '' ? $caBundle : null;

        $wsdl = config('fiscal_diagnostics.consult_wsdl');
        $wsdlPath = is_string($wsdl) && $wsdl !== '' ? $wsdl : WsdlProvider::getConsultPath();

        if (! is_file($wsdlPath) || ! is_readable($wsdlPath)) {
            throw new RuntimeException('fiscal_diagnostics_consult_wsdl_unavailable');
        }

        $client = new VerifiedConsultSoapClient(
            $wsdlPath,
            $caBundlePath,
            $this->boundedConnectionTimeout(),
            $this->boundedReadTimeout(),
        );

        return new FiscalProfileDiagnosticsService(
            new GreenterConsultCdrProbe(
                client: $client,
                transportTrusted: true,
                documentType: $this->consultDocumentType(),
                series: $this->consultSeries(),
                correlative: $this->consultCorrelative(),
            ),
            identityMatcher: $identityMatcher,
            xmlSignatureProbe: $xmlSignatureProbe,
        );
    }

    private function configuredIdentityMatcher(): ?CertificateIdentityMatcher
    {
        $raw = config('fiscal_diagnostics.identity_profile');
        if ($raw === null) {
            return null;
        }
        $required = ['issuer_certificate_der_base64', 'issuer_sha256_der',
            'profile_id', 'policy_oid', 'ruc_attribute_oid'];
        $allowed = [...$required, 'ruc_attribute_prefix'];
        if (! is_array($raw) || count($raw) !== count($allowed)
            || array_diff(array_keys($raw), $allowed) !== []
            || array_diff($allowed, array_keys($raw)) !== []) {
            throw new RuntimeException('fiscal_diagnostics_identity_configuration_invalid');
        }
        $configured = $raw['ruc_attribute_prefix'] !== '';
        foreach ($required as $key) {
            $configured = $configured || $raw[$key] !== null;
        }
        if (! $configured) {
            return null;
        }
        foreach ($allowed as $key) {
            if (! is_string($raw[$key]) || ($key !== 'ruc_attribute_prefix' && $raw[$key] === '')) {
                throw new RuntimeException('fiscal_diagnostics_identity_configuration_invalid');
            }
        }
        try {
            $encoded = $raw['issuer_certificate_der_base64'];
            if (strlen($encoded) > 90000) {
                throw new RuntimeException();
            }
            $der = base64_decode($encoded, true);
            if (! is_string($der) || $der === '' || strlen($der) > 65536
                || ! hash_equals(base64_encode($der), $encoded)) {
                throw new RuntimeException();
            }
            $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($encoded, 64, "\n")
                ."-----END CERTIFICATE-----\n";
            return new ConfiguredCertificateIdentityMatcher(
                new CertificateIdentityProfileConfiguration(
                    $pem, $raw['issuer_sha256_der'], [new CertificateIdentityProfile(
                        $raw['profile_id'], $raw['policy_oid'], $raw['ruc_attribute_oid'],
                        $raw['ruc_attribute_prefix'],
                    )],
                ),
            );
        } catch (\Throwable) {
            throw new RuntimeException('fiscal_diagnostics_identity_configuration_invalid');
        }
    }

    private function consultDocumentType(): string
    {
        $raw = config('fiscal_diagnostics.consult_document_type', '01');

        if (! is_string($raw) || preg_match('/^[0-9]{2}$/', $raw) !== 1) {
            throw new RuntimeException('fiscal_diagnostics_consult_document_type_invalid');
        }

        return $raw;
    }

    private function consultSeries(): string
    {
        $raw = config('fiscal_diagnostics.consult_series', 'F001');

        if (! is_string($raw) || preg_match('/^[A-Za-z0-9]{1,4}$/', $raw) !== 1) {
            throw new RuntimeException('fiscal_diagnostics_consult_series_invalid');
        }

        return $raw;
    }

    private function consultCorrelative(): int
    {
        $raw = config('fiscal_diagnostics.consult_correlative', 1);

        if (is_string($raw) && ctype_digit($raw)) {
            $raw = (int) $raw;
        }

        if (! is_int($raw) || $raw < 1 || $raw > 99_999_999) {
            throw new RuntimeException('fiscal_diagnostics_consult_correlative_invalid');
        }

        return $raw;
    }

    private function boundedConnectionTimeout(): int
    {
        $raw = config('fiscal_diagnostics.consult_timeout_seconds', 10);

        if (is_string($raw) && ctype_digit($raw)) {
            $raw = (int) $raw;
        }

        if (! is_int($raw) || $raw < 1 || $raw > 30) {
            throw new RuntimeException('fiscal_diagnostics_consult_timeout_invalid');
        }

        return $raw;
    }

    private function boundedReadTimeout(): int
    {
        $raw = config('fiscal_diagnostics.consult_read_timeout_seconds', 15);

        if (is_string($raw) && ctype_digit($raw)) {
            $raw = (int) $raw;
        }

        if (! is_int($raw) || $raw < 1 || $raw > 30) {
            throw new RuntimeException('fiscal_diagnostics_consult_read_timeout_invalid');
        }

        return $raw;
    }
}
