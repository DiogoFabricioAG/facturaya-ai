<?php

namespace App\Data;

/**
 * Reporte de diagnóstico fiscal backend sin secretos.
 *
 * La sección `external` usa los mismos nombres que
 * `ExternalFiscalProbeResult` (meowlab-platform) para que un adaptador
 * `ExternalFiscalProbePort` pueda mapearla, pero este reporte por sí solo no
 * acredita autenticación, firma, confianza CA ni revocación: esas banderas solo
 * pueden ser verdaderas con evidencia SUNAT real y verificación TLS habilitada.
 *
 * `toArray()` conserva el envelope legacy sin cambios. La evidencia técnica
 * aditiva (`technical_evidence`, schema versionado) viaja como propiedad
 * separada y el controller la expone como objeto de nivel superior: ningún
 * consumidor legacy recibe claves nuevas dentro de `diagnostics`.
 */
final readonly class FiscalProfileDiagnosticReport
{
    public const CONTRACT_VERSION = 1;

    /**
     * @param  list<string>  $testedPermissions
     * @param  list<string>  $pendingReasons
     * @param  list<array{check: string, status: string, source: string, reason: ?string}>  $checks
     */
    public function __construct(
        public string $profileReference,
        public int $configVersion,
        public string $environment,
        public string $issuerRuc,
        public string $evaluatedAt,
        public string $timezone,
        public bool $certificateLoaded,
        public bool $privateKeyLoaded,
        public bool $certificateKeyCompatible,
        public ?string $publicKeyAlgorithm,
        public ?string $certificateValidFrom,
        public ?string $certificateValidUntil,
        public bool $certificateCurrentlyValid,
        public bool $localSignatureVerified,
        public ?string $localSignatureAlgorithm,
        public string $externalOutcome,
        public ?string $externalEndpointHost,
        public ?string $externalOccurredAt,
        public bool $credentialsTested,
        public array $testedPermissions,
        public bool $certificateTested,
        public bool $signatureTested,
        public bool $caChainVerified,
        public bool $revocationVerified,
        public ?string $probeValidUntil,
        public ?string $sunatCode,
        public ?string $sunatMessage,
        public array $pendingReasons,
        public array $checks = [],
        public ?FiscalTechnicalEvidence $technicalEvidence = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'provider' => 'facturaya-php',
            'binding' => [
                'profile_reference' => $this->profileReference,
                'config_version' => $this->configVersion,
                'environment' => $this->environment,
                'issuer_ruc' => $this->issuerRuc,
                'evaluated_at' => $this->evaluatedAt,
                'timezone' => $this->timezone,
            ],
            'local' => [
                'certificate_loaded' => $this->certificateLoaded,
                'private_key_loaded' => $this->privateKeyLoaded,
                'certificate_key_compatible' => $this->certificateKeyCompatible,
                'public_key_algorithm' => $this->publicKeyAlgorithm,
                'certificate_valid_from' => $this->certificateValidFrom,
                'certificate_valid_until' => $this->certificateValidUntil,
                'certificate_currently_valid' => $this->certificateCurrentlyValid,
                'local_signature_verified' => $this->localSignatureVerified,
                'local_signature_algorithm' => $this->localSignatureAlgorithm,
            ],
            'external' => [
                'outcome' => $this->externalOutcome,
                'endpoint_host' => $this->externalEndpointHost,
                'occurred_at' => $this->externalOccurredAt,
                'credentials_tested' => $this->credentialsTested,
                'tested_permissions' => array_values($this->testedPermissions),
                'certificate_tested' => $this->certificateTested,
                'signature_tested' => $this->signatureTested,
                'ca_chain_verified' => $this->caChainVerified,
                'revocation_verified' => $this->revocationVerified,
                'probe_valid_until' => $this->probeValidUntil,
                'sunat_code' => $this->sunatCode,
                'sunat_message' => $this->sunatMessage,
            ],
            'pending_reasons' => array_values(array_unique($this->pendingReasons)),
            'checks' => array_values($this->checks),
        ];
    }
}
