<?php

namespace App\Services\Sunat;

use App\Data\CertificateIdentityMatchResult;
use App\Data\FiscalExternalProbeOutcome;
use App\Data\FiscalProfileDiagnosticInput;
use App\Data\FiscalProfileDiagnosticReport;
use App\Data\FiscalTechnicalEvidence;
use App\Data\LocalXmlSignatureResult;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Diagnóstico backend del perfil fiscal sin emisión y sin persistencia.
 *
 * Comprueba en memoria el PKCS#12/PEM y la firma local (desafío RSA-SHA1 fijo,
 * nunca XMLDSig/UBL ni el motor Greenter); opcionalmente delega una consulta
 * SUNAT no mutante a un puerto inyectado. La identidad del certificado solo se
 * evalúa con un matcher confiable inyectado desde el servidor: sin él la
 * evidencia queda `pending` y no autoriza. Nunca guarda secretos en base de
 * datos, archivos ni logs, y nunca devuelve credenciales en el reporte. La
 * validez local no acredita compatibilidad SUNAT, cadena CA, revocación ni
 * aprobación de emisión.
 */
final class FiscalProfileDiagnosticsService
{
    private const LOCAL_SIGNATURE_PAYLOAD = 'MEOWLAB-FISCAL-DIAGNOSTICS-LOCAL-SIGNATURE-V1';

    private const LOCAL_SIGNATURE_ALGORITHM = 'RSA-SHA1';

    private const TIMEZONE = 'UTC';

    private const CHECK_VERIFIED = 'verified';

    private const CHECK_FAILED = 'failed';

    private const CHECK_PENDING = 'pending';

    private const CHECK_UNSUPPORTED = 'unsupported';

    private const SOURCE_LOCAL_OPENSSL = 'local_openssl';

    private const SOURCE_LOCAL_CERTIFICATE = 'local_certificate_material';

    private const SOURCE_LOCAL_SIGNATURE = 'local_engine_signature';

    private const SOURCE_GREENTER_LOCAL_XML_SIGNATURE = 'greenter_local_xml_signature';

    private const SOURCE_TRUST_INFRASTRUCTURE = 'infrastructure_trust_bundle_required';

    private const SOURCE_SERVER_IDENTITY_MATCHER = 'server_identity_matcher';

    private const SOURCE_SUNAT_CONSULT = 'sunat_billconsult_getstatuscdr';

    private const SOURCE_PENDING_DASHBOARD = 'pending_dashboard_execution';

    public function __construct(
        private readonly ?SunatNonIssuingProbe $externalProbe = null,
        private readonly ?CertificateIdentityMatcher $identityMatcher = null,
        private readonly ?LocalXmlSignatureProbe $xmlSignatureProbe = null,
    ) {}

    public function diagnose(FiscalProfileDiagnosticInput $input): FiscalProfileDiagnosticReport
    {
        $local = $this->evaluateLocalCertificate($input);
        $pending = $this->localPendingReasons($local);
        $outcome = $this->evaluateExternalProbe($input, $pending);

        $credentialsTested = $outcome->transportTrusted
            && $outcome->outcome === FiscalExternalProbeOutcome::OUTCOME_AUTHENTICATION_ACCEPTED;

        $xmlSignature = $this->xmlSignatureEvidence($local);
        $checks = $this->buildChecks($local, $outcome, $credentialsTested, $xmlSignature);

        foreach ($checks as $check) {
            if (
                in_array($check['status'], [self::CHECK_PENDING, self::CHECK_UNSUPPORTED], true)
                && $check['reason'] !== null
            ) {
                $pending[] = $check['reason'];
            }
        }

        $technicalEvidence = $this->buildTechnicalEvidence($input, $local, $outcome, $credentialsTested, $xmlSignature);

        return new FiscalProfileDiagnosticReport(
            profileReference: $input->profileReference,
            configVersion: $input->configVersion,
            environment: $input->environment,
            issuerRuc: $input->issuerRuc,
            evaluatedAt: $this->now(),
            timezone: self::TIMEZONE,
            certificateLoaded: $local['certificate_loaded'],
            privateKeyLoaded: $local['private_key_loaded'],
            certificateKeyCompatible: $local['certificate_key_compatible'],
            publicKeyAlgorithm: $local['public_key_algorithm'],
            certificateValidFrom: $local['certificate_valid_from'],
            certificateValidUntil: $local['certificate_valid_until'],
            certificateCurrentlyValid: $local['certificate_currently_valid'],
            localSignatureVerified: $local['local_signature_verified'],
            localSignatureAlgorithm: $local['local_signature_algorithm'],
            externalOutcome: $outcome->outcome,
            externalEndpointHost: $outcome->endpointHost,
            externalOccurredAt: $outcome->occurredAt,
            credentialsTested: $credentialsTested,
            testedPermissions: [],
            certificateTested: false,
            signatureTested: false,
            caChainVerified: false,
            revocationVerified: false,
            probeValidUntil: null,
            sunatCode: $outcome->sunatCode,
            sunatMessage: $outcome->sunatMessage,
            pendingReasons: array_values(array_unique($pending)),
            checks: $checks,
            technicalEvidence: $technicalEvidence,
        );
    }

    /**
     * Evidencia por comprobación con fuente verificable; las no verificables
     * quedan `pending`/`unsupported` con el requisito explícito para cerrarlas.
     * El check del firmador real de Greenter se añade solo cuando el motor local
     * está configurado; el challenge RSA-SHA1 legacy permanece etiquetado como
     * `local_openssl` y no se retira.
     *
     * @param  array<string, mixed>  $local
     * @param  array{status: string, source: string, reason: ?string}|null  $xmlSignature
     * @return list<array{check: string, status: string, source: string, reason: ?string}>
     */
    private function buildChecks(array $local, FiscalExternalProbeOutcome $outcome, bool $credentialsTested, ?array $xmlSignature = null): array
    {
        $certificateLoaded = $local['certificate_loaded'];
        $keyLoaded = $local['private_key_loaded'];
        $rsa = $local['public_key_algorithm'] === 'rsa';

        $checks = [
            $this->check(
                'certificate_readable',
                $certificateLoaded,
                self::SOURCE_LOCAL_OPENSSL,
                'certificate_not_readable_in_memory',
            ),
            $this->check(
                'private_key_readable',
                $keyLoaded,
                self::SOURCE_LOCAL_OPENSSL,
                $certificateLoaded ? 'private_key_not_readable_in_memory' : 'certificate_not_readable_in_memory',
            ),
            $this->check(
                'key_pair_matches',
                $keyLoaded && $local['certificate_key_compatible'],
                self::SOURCE_LOCAL_OPENSSL,
                $keyLoaded ? 'certificate_key_pair_not_verified' : 'private_key_not_readable_in_memory',
            ),
            $this->check(
                'certificate_currently_valid',
                $certificateLoaded && $local['certificate_currently_valid'],
                self::SOURCE_LOCAL_OPENSSL,
                'certificate_not_currently_valid',
            ),
            $this->check(
                'local_signature_sha1',
                $rsa && $local['local_signature_verified'],
                self::SOURCE_LOCAL_OPENSSL,
                $rsa ? 'local_signature_not_verified' : 'certificate_key_not_rsa',
            ),
            $this->externalCredentialCheck($outcome, $credentialsTested),
        ];

        if ($xmlSignature !== null) {
            $checks[] = [
                'check' => 'greenter_local_xml_signature',
                'status' => match ($xmlSignature['status']) {
                    FiscalTechnicalEvidence::STATUS_VERIFIED => self::CHECK_VERIFIED,
                    FiscalTechnicalEvidence::STATUS_REJECTED => self::CHECK_FAILED,
                    default => self::CHECK_PENDING,
                },
                'source' => $xmlSignature['source'],
                'reason' => $xmlSignature['reason'],
            ];
        }

        return [
            ...$checks,
            $this->pendingCheck('certificate_sunat_acceptance', 'requires_signed_document_submission'),
            $this->pendingCheck('signature_xmlsig_sunat', 'requires_signed_submission_validation'),
            $this->pendingCheck('ca_chain_trust', 'requires_validated_trust_anchor'),
            $this->pendingCheck('revocation_status', 'requires_crl_ocsp_service'),
            $this->pendingCheck('emission_permissions', 'not_proven_by_non_issuing_consult'),
            $this->pendingCheck('sunat_acceptance', 'requires_authorized_submission'),
        ];
    }

    /**
     * @return array{check: string, status: string, source: string, reason: ?string}
     */
    private function check(string $name, bool $verified, string $source, string $failureReason): array
    {
        return [
            'check' => $name,
            'status' => $verified ? self::CHECK_VERIFIED : self::CHECK_FAILED,
            'source' => $source,
            'reason' => $verified ? null : $failureReason,
        ];
    }

    /**
     * @return array{check: string, status: string, source: string, reason: ?string}
     */
    private function pendingCheck(string $name, string $reason): array
    {
        return [
            'check' => $name,
            'status' => self::CHECK_PENDING,
            'source' => self::SOURCE_PENDING_DASHBOARD,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{check: string, status: string, source: string, reason: ?string}
     */
    private function externalCredentialCheck(FiscalExternalProbeOutcome $outcome, bool $credentialsTested): array
    {
        if ($credentialsTested) {
            return [
                'check' => 'ruc_sol_credentials',
                'status' => self::CHECK_VERIFIED,
                'source' => self::SOURCE_SUNAT_CONSULT,
                'reason' => null,
            ];
        }

        return match ($outcome->outcome) {
            FiscalExternalProbeOutcome::OUTCOME_AUTHENTICATION_ACCEPTED => [
                'check' => 'ruc_sol_credentials',
                'status' => self::CHECK_PENDING,
                'source' => self::SOURCE_SUNAT_CONSULT,
                'reason' => 'external_transport_not_verified',
            ],
            FiscalExternalProbeOutcome::OUTCOME_AUTHENTICATION_REJECTED => [
                'check' => 'ruc_sol_credentials',
                'status' => self::CHECK_FAILED,
                'source' => self::SOURCE_SUNAT_CONSULT,
                'reason' => 'sunat_authentication_rejected',
            ],
            FiscalExternalProbeOutcome::OUTCOME_ENVIRONMENT_UNSUPPORTED => [
                'check' => 'ruc_sol_credentials',
                'status' => self::CHECK_UNSUPPORTED,
                'source' => self::SOURCE_SUNAT_CONSULT,
                'reason' => 'non_mutating_consult_not_available_for_environment',
            ],
            FiscalExternalProbeOutcome::OUTCOME_NOT_ATTEMPTED => [
                'check' => 'ruc_sol_credentials',
                'status' => self::CHECK_PENDING,
                'source' => self::SOURCE_PENDING_DASHBOARD,
                'reason' => 'external_probe_not_configured',
            ],
            default => [
                'check' => 'ruc_sol_credentials',
                'status' => self::CHECK_PENDING,
                'source' => self::SOURCE_SUNAT_CONSULT,
                'reason' => 'external_probe_result_unverified',
            ],
        };
    }

    /**
     * Evidencia técnica aditiva (schema versionado). No declara readiness
     * completo: solo describe qué comprobó este servicio y con qué fuente.
     * La firma local nunca se etiqueta como aceptación SUNAT y los permisos no
     * se infieren de ninguna consulta.
     *
     * @param  array<string, mixed>  $local
     * @param  array{status: string, source: string, reason: ?string}|null  $xmlSignature
     */
    private function buildTechnicalEvidence(
        FiscalProfileDiagnosticInput $input,
        array $local,
        FiscalExternalProbeOutcome $outcome,
        bool $credentialsTested,
        ?array $xmlSignature = null,
    ): FiscalTechnicalEvidence {
        $publicPem = $local['certificate_pem'] ?? null;
        $fingerprint = is_string($publicPem) && $publicPem !== ''
            ? @openssl_x509_fingerprint($publicPem, 'sha256') : false;
        return new FiscalTechnicalEvidence(
            credentials: $this->credentialsEvidence($input, $outcome, $credentialsTested),
            certificate: $this->certificateEvidence($input, $local),
            signature: $xmlSignature ?? $this->challengeSignatureEvidence($local),
            trust: [
                'status' => FiscalTechnicalEvidence::STATUS_PENDING,
                'source' => self::SOURCE_TRUST_INFRASTRUCTURE,
                'reason' => 'requires_validated_trust_anchor',
            ],
            certificateDerSha256: is_string($fingerprint) ? strtolower($fingerprint) : null,
        );
    }

    /**
     * Solo la consulta no emisora puede verificar credenciales; sin transporte
     * confiable o sin resultado clasificado queda `pending`/`rejected` opaco.
     * Nunca acredita los seis permisos ni la aceptación de emisión.
     *
     * @return array{status: string, source: string, environment: string, reason: ?string}
     */
    private function credentialsEvidence(
        FiscalProfileDiagnosticInput $input,
        FiscalExternalProbeOutcome $outcome,
        bool $credentialsTested,
    ): array {
        $environment = $input->environment;

        if ($credentialsTested) {
            return [
                'status' => FiscalTechnicalEvidence::STATUS_VERIFIED,
                'source' => self::SOURCE_SUNAT_CONSULT,
                'environment' => $environment,
                'reason' => null,
            ];
        }

        return match ($outcome->outcome) {
            FiscalExternalProbeOutcome::OUTCOME_AUTHENTICATION_ACCEPTED => [
                'status' => FiscalTechnicalEvidence::STATUS_PENDING,
                'source' => self::SOURCE_SUNAT_CONSULT,
                'environment' => $environment,
                'reason' => 'external_transport_not_verified',
            ],
            FiscalExternalProbeOutcome::OUTCOME_AUTHENTICATION_REJECTED => [
                'status' => FiscalTechnicalEvidence::STATUS_REJECTED,
                'source' => self::SOURCE_SUNAT_CONSULT,
                'environment' => $environment,
                'reason' => 'sunat_authentication_rejected',
            ],
            FiscalExternalProbeOutcome::OUTCOME_ENVIRONMENT_UNSUPPORTED => [
                'status' => FiscalTechnicalEvidence::STATUS_PENDING,
                'source' => self::SOURCE_SUNAT_CONSULT,
                'environment' => $environment,
                'reason' => 'non_mutating_consult_not_available_for_environment',
            ],
            FiscalExternalProbeOutcome::OUTCOME_NOT_ATTEMPTED => [
                'status' => FiscalTechnicalEvidence::STATUS_PENDING,
                'source' => self::SOURCE_PENDING_DASHBOARD,
                'environment' => $environment,
                'reason' => 'external_probe_not_configured',
            ],
            default => [
                'status' => FiscalTechnicalEvidence::STATUS_PENDING,
                'source' => self::SOURCE_SUNAT_CONSULT,
                'environment' => $environment,
                'reason' => 'external_probe_result_unverified',
            ],
        };
    }

    /**
     * Correspondencia del certificado cargado. `verified` exige material
     * legible, vigente, par de claves compatibles y un matcher de identidad
     * inyectado explícitamente por el servidor que devuelva `match`. Sin
     * matcher, la identidad queda `pending` (`certificate_issuer_identity_not_
     * demonstrated`): ningún marcador de subject, CN ni RUC copiado del request
     * autoriza. `unknown`/`ambiguous` permanecen `pending`; solo el matcher
     * confiable puede expresar `conflict`, que no es una afirmación tributaria
     * de SUNAT.
     *
     * @param  array<string, mixed>  $local
     * @return array{status: string, source: string, reason: ?string}
     */
    private function certificateEvidence(FiscalProfileDiagnosticInput $input, array $local): array
    {
        if (! $local['certificate_loaded']) {
            return $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_REJECTED, self::SOURCE_LOCAL_CERTIFICATE, 'certificate_not_readable_in_memory');
        }

        if (! $local['certificate_currently_valid']) {
            return $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_REJECTED, self::SOURCE_LOCAL_CERTIFICATE, 'certificate_not_currently_valid');
        }

        if ($local['private_key_loaded'] && ! $local['certificate_key_compatible']) {
            return $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_REJECTED, self::SOURCE_LOCAL_CERTIFICATE, 'certificate_key_pair_not_verified');
        }

        if (! $local['private_key_loaded']) {
            return $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_PENDING, self::SOURCE_LOCAL_CERTIFICATE, 'private_key_not_readable_in_memory');
        }

        return $this->identityEvidence($input, $local);
    }

    /**
     * La identidad tributaria solo se evalúa con el matcher confiable del
     * servidor y con el certificado público exportado en memoria. Sin matcher
     * no hay fuente admisible: pending opaco, sin heurísticas de subject.
     *
     * @param  array<string, mixed>  $local
     * @return array{status: string, source: string, reason: ?string}
     */
    private function identityEvidence(FiscalProfileDiagnosticInput $input, array $local): array
    {
        $matcher = $this->identityMatcher;

        if ($matcher === null) {
            return $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_PENDING, self::SOURCE_LOCAL_CERTIFICATE, 'certificate_issuer_identity_not_demonstrated');
        }

        $certificatePem = $local['certificate_pem'] ?? null;

        if (! is_string($certificatePem) || $certificatePem === '') {
            return $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_PENDING, self::SOURCE_SERVER_IDENTITY_MATCHER, 'certificate_public_material_unavailable');
        }

        try {
            $result = $matcher->match($certificatePem, $input->issuerRuc);
        } catch (Throwable) {
            return $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_PENDING, self::SOURCE_SERVER_IDENTITY_MATCHER, 'certificate_identity_matcher_unavailable');
        }

        if (! $result instanceof CertificateIdentityMatchResult) {
            return $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_PENDING, self::SOURCE_SERVER_IDENTITY_MATCHER, 'certificate_identity_matcher_result_invalid');
        }

        return match ($result->status) {
            CertificateIdentityMatchResult::STATUS_MATCH => $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_VERIFIED, self::SOURCE_SERVER_IDENTITY_MATCHER, $result->reason),
            CertificateIdentityMatchResult::STATUS_AMBIGUOUS => $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_PENDING, self::SOURCE_SERVER_IDENTITY_MATCHER, 'certificate_issuer_identity_ambiguous'),
            CertificateIdentityMatchResult::STATUS_CONFLICT => $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_REJECTED, self::SOURCE_SERVER_IDENTITY_MATCHER, 'certificate_issuer_identity_conflict'),
            default => $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_PENDING, self::SOURCE_SERVER_IDENTITY_MATCHER, 'certificate_issuer_identity_not_demonstrated'),
        };
    }

    /**
     * Firma XMLDSig real del motor Greenter sobre el XML diagnóstico, sin red
     * ni comprobante. Devuelve `null` cuando el motor no está configurado: en
     * ese caso la evidencia de firma cae al challenge RSA-SHA1 etiquetado como
     * `local_engine_signature`. El nuevo `source: greenter_local_xml_signature`
     * solo aparece con el motor configurado, y `verified` solo si el XML real
     * firmado y verificado en memoria con el certificado cargado lo confirma.
     *
     * @param  array<string, mixed>  $local
     * @return array{status: string, source: string, reason: ?string}|null
     */
    private function xmlSignatureEvidence(array $local): ?array
    {
        if ($this->xmlSignatureProbe === null) {
            return null;
        }

        if (! $local['certificate_loaded']) {
            return $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_PENDING, self::SOURCE_GREENTER_LOCAL_XML_SIGNATURE, 'certificate_not_readable_in_memory');
        }

        if (! $local['private_key_loaded']) {
            return $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_PENDING, self::SOURCE_GREENTER_LOCAL_XML_SIGNATURE, 'private_key_not_readable_in_memory');
        }

        if ($local['public_key_algorithm'] !== 'rsa') {
            return $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_REJECTED, self::SOURCE_GREENTER_LOCAL_XML_SIGNATURE, 'certificate_key_not_rsa');
        }

        $combinedPem = $local['combined_pem'] ?? null;

        if (! is_string($combinedPem) || $combinedPem === '') {
            return $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_PENDING, self::SOURCE_GREENTER_LOCAL_XML_SIGNATURE, 'greenter_local_xml_signature_material_unavailable');
        }

        try {
            $result = $this->xmlSignatureProbe->signAndVerify($combinedPem);
        } catch (Throwable) {
            return $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_PENDING, self::SOURCE_GREENTER_LOCAL_XML_SIGNATURE, 'greenter_local_xml_signature_engine_unavailable');
        }

        if (! $result instanceof LocalXmlSignatureResult) {
            return $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_PENDING, self::SOURCE_GREENTER_LOCAL_XML_SIGNATURE, 'greenter_local_xml_signature_result_invalid');
        }

        if ($result->status === LocalXmlSignatureResult::STATUS_VERIFIED) {
            return $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_VERIFIED, self::SOURCE_GREENTER_LOCAL_XML_SIGNATURE, null);
        }

        return $this->evidenceBlock(
            FiscalTechnicalEvidence::STATUS_REJECTED,
            self::SOURCE_GREENTER_LOCAL_XML_SIGNATURE,
            $result->reason ?? 'greenter_local_xml_signature_verification_failed',
        );
    }

    /**
     * Challenge RSA-SHA1 local (`local_engine_signature`): firma y verificación
     * de una carga fija con OpenSSL, no XMLDSig/UBL ni el motor Greenter. Es el
     * fallback honesto cuando el motor XML real no está configurado; nunca se
     * etiqueta como aceptación SUNAT.
     *
     * @param  array<string, mixed>  $local
     * @return array{status: string, source: string, reason: ?string}
     */
    private function challengeSignatureEvidence(array $local): array
    {
        if (! $local['certificate_loaded']) {
            return $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_PENDING, self::SOURCE_LOCAL_SIGNATURE, 'certificate_not_readable_in_memory');
        }

        if (! $local['private_key_loaded']) {
            return $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_PENDING, self::SOURCE_LOCAL_SIGNATURE, 'private_key_not_readable_in_memory');
        }

        if ($local['public_key_algorithm'] !== 'rsa') {
            return $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_REJECTED, self::SOURCE_LOCAL_SIGNATURE, 'certificate_key_not_rsa');
        }

        if (! $local['local_signature_verified']) {
            return $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_REJECTED, self::SOURCE_LOCAL_SIGNATURE, 'local_signature_not_verified');
        }

        return $this->evidenceBlock(FiscalTechnicalEvidence::STATUS_VERIFIED, self::SOURCE_LOCAL_SIGNATURE, null);
    }

    /**
     * @return array{status: string, source: string, reason: ?string}
     */
    private function evidenceBlock(string $status, string $source, ?string $reason): array
    {
        return [
            'status' => $status,
            'source' => $source,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{
     *   certificate_loaded: bool,
     *   private_key_loaded: bool,
     *   certificate_key_compatible: bool,
     *   public_key_algorithm: ?string,
     *   certificate_valid_from: ?string,
     *   certificate_valid_until: ?string,
     *   certificate_currently_valid: bool,
     *   local_signature_verified: bool,
     *   local_signature_algorithm: ?string,
     *   certificate_pem: ?string,
     *   combined_pem: ?string
     * }
     */
    private function evaluateLocalCertificate(FiscalProfileDiagnosticInput $input): array
    {
        $result = [
            'certificate_loaded' => false,
            'private_key_loaded' => false,
            'certificate_key_compatible' => false,
            'public_key_algorithm' => null,
            'certificate_valid_from' => null,
            'certificate_valid_until' => null,
            'certificate_currently_valid' => false,
            'local_signature_verified' => false,
            'local_signature_algorithm' => null,
            'certificate_pem' => null,
            'combined_pem' => null,
        ];

        $pem = $this->readPem($input);

        if ($pem === null) {
            return $result;
        }

        $result['combined_pem'] = $pem;

        $certificate = @openssl_x509_read($pem);

        if ($certificate === false) {
            return $result;
        }

        $result['certificate_loaded'] = true;

        $parsed = @openssl_x509_parse($certificate);

        if (is_array($parsed)) {
            $result['certificate_valid_from'] = $this->timestamp($parsed['validFrom_time_t'] ?? null);
            $result['certificate_valid_until'] = $this->timestamp($parsed['validTo_time_t'] ?? null);
            $result['certificate_currently_valid'] = $this->isCurrentlyValid($parsed);
        }

        $publicPem = '';

        if (@openssl_x509_export($certificate, $publicPem)) {
            $result['certificate_pem'] = trim($publicPem) !== '' ? trim($publicPem) : null;
        }

        $privateKey = @openssl_pkey_get_private($pem);

        if ($privateKey === false) {
            return $result;
        }

        $result['private_key_loaded'] = true;

        $details = @openssl_pkey_get_details($privateKey);

        if (is_array($details)) {
            $result['public_key_algorithm'] = ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA ? 'rsa' : 'other';
        }

        $result['certificate_key_compatible'] = @openssl_x509_check_private_key($certificate, $privateKey) === true;

        if ($result['public_key_algorithm'] === 'rsa') {
            $signature = '';

            if (@openssl_sign(self::LOCAL_SIGNATURE_PAYLOAD, $signature, $privateKey, OPENSSL_ALGO_SHA1)) {
                $verified = @openssl_verify(self::LOCAL_SIGNATURE_PAYLOAD, $signature, $certificate, OPENSSL_ALGO_SHA1);
                $result['local_signature_verified'] = $verified === 1;
                $result['local_signature_algorithm'] = self::LOCAL_SIGNATURE_ALGORITHM;
            }

            unset($signature);
        }

        return $result;
    }

    private function readPem(FiscalProfileDiagnosticInput $input): ?string
    {
        if (! $input->usesPkcs12()) {
            return $input->certificatePem();
        }

        $this->clearOpenSslErrors();
        $certificates = [];

        if (! @openssl_pkcs12_read($input->pkcs12Contents(), $certificates, $input->pkcs12Passphrase())) {
            $this->clearOpenSslErrors();

            return null;
        }

        $certificate = $certificates['cert'] ?? null;
        $privateKey = $certificates['pkey'] ?? null;

        if (! is_string($certificate) || ! is_string($privateKey)) {
            return null;
        }

        return trim($privateKey).PHP_EOL.trim($certificate).PHP_EOL;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function isCurrentlyValid(array $parsed): bool
    {
        $from = $parsed['validFrom_time_t'] ?? null;
        $until = $parsed['validTo_time_t'] ?? null;
        $now = time();

        return is_int($from) && is_int($until) && $from <= $now && $now <= $until;
    }

    private function timestamp(mixed $value): ?string
    {
        if (! is_int($value)) {
            return null;
        }

        return (new DateTimeImmutable('@'.$value))->setTimezone(new DateTimeZone(self::TIMEZONE))->format(DATE_ATOM);
    }

    /**
     * @param  list<string>  $pending
     */
    private function evaluateExternalProbe(FiscalProfileDiagnosticInput $input, array &$pending): FiscalExternalProbeOutcome
    {
        if ($this->externalProbe === null) {
            $pending[] = 'external_probe_not_configured';

            return FiscalExternalProbeOutcome::notAttempted();
        }

        try {
            $outcome = $this->externalProbe->probe($input);
        } catch (Throwable) {
            $pending[] = 'external_probe_invocation_failed';

            return new FiscalExternalProbeOutcome(
                outcome: FiscalExternalProbeOutcome::OUTCOME_TRANSPORT_ERROR,
                occurredAt: $this->now(),
            );
        }

        if (! $outcome->transportTrusted) {
            $pending[] = 'external_transport_not_verified';
        }

        if ($outcome->outcome === FiscalExternalProbeOutcome::OUTCOME_ENVIRONMENT_UNSUPPORTED) {
            $pending[] = 'non_mutating_consult_not_available_for_environment';
        }

        if ($outcome->outcome === FiscalExternalProbeOutcome::OUTCOME_NOT_ATTEMPTED) {
            $pending[] = 'external_probe_not_attempted';
        }

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $local
     * @return list<string>
     */
    private function localPendingReasons(array $local): array
    {
        if (! $local['certificate_loaded']) {
            return ['certificate_not_readable_in_memory'];
        }

        $pending = [];

        if (! $local['private_key_loaded']) {
            $pending[] = 'private_key_not_readable_in_memory';
        }

        if ($local['public_key_algorithm'] !== 'rsa') {
            $pending[] = 'certificate_key_not_rsa';
        }

        if (! $local['certificate_currently_valid']) {
            $pending[] = 'certificate_not_currently_valid';
        }

        if ($local['private_key_loaded'] && ! $local['certificate_key_compatible']) {
            $pending[] = 'certificate_key_pair_not_verified';
        }

        if ($local['public_key_algorithm'] === 'rsa' && ! $local['local_signature_verified']) {
            $pending[] = 'local_signature_not_verified';
        }

        return $pending;
    }

    private function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // Descarta errores previos para que no contaminen la siguiente lectura.
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))->format(DATE_ATOM);
    }
}
