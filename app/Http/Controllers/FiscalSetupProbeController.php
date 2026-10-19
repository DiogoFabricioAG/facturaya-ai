<?php

namespace App\Http\Controllers;

use App\Data\FiscalProfileDiagnosticInput;
use App\Data\FiscalProfileDiagnosticReport;
use App\Services\Sunat\FiscalDiagnosticsRuntimeFactory;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Endpoint interno M2M POST /api/fiscal-setup/probe.
 *
 * Recibe el snapshot fiscal (tenant, versión, ambiente, referencias opacas) con
 * credenciales secundarias y PKCS#12 efímeros, ejecuta el diagnóstico local sin
 * emitir y responde nivel `pending`. La respuesta incluye la lista `checks` con
 * la fuente verificable de cada comprobación y el requisito explícito de las no
 * verificables (CA, revocación, permisos, firma XMLDSig y aceptación SUNAT).
 * Añade de forma aditiva `technical_evidence` (schema versionado) con bloques
 * credentials/certificate/signature/trust: la firma XMLDSig real del motor
 * Greenter (`source: greenter_local_xml_signature`) se distingue de la
 * aceptación SUNAT y del challenge RSA-SHA1 legacy (`local_engine_signature`);
 * ninguna consulta acredita los seis permisos y la identidad tributaria del
 * certificado solo puede verificarse con un matcher de servidor inyectado
 * explícitamente; sin él la evidencia queda pending y ningún marcador de
 * subject la autoriza.
 * Nunca devuelve `success`, secretos, mensajes SUNAT crudos ni contenido del
 * cuerpo. Sin persistencia: no requiere mapeo tenant↔empresa.
 */
final class FiscalSetupProbeController extends Controller
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const RUC_PATTERN = '/^[0-9]{11}$/';

    private const SOL_USERNAME_PATTERN = '/^[A-Za-z0-9_.-]{4,32}$/';

    private const SOL_USER_REF_PATTERN = '/^sec-user-[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i';

    private const CERTIFICATE_REF_PATTERN = '/^cert-[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i';

    private const MAX_SOL_PASSWORD_LENGTH = 128;

    private const MAX_CERTIFICATE_PASSPHRASE_LENGTH = 512;

    private const MAX_REQUESTED_AT_LENGTH = 64;

    private const REQUESTED_AT_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/';

    private const MAX_CERTIFICATE_BASE64_LENGTH = 90_000;

    private const MIN_CERTIFICATE_BYTES = 64;

    private const MAX_PERMISSIONS = 6;

    private const REQUEST_KEYS = [
        'tenant_id',
        'config_version',
        'environment',
        'sol_secondary_user_ref',
        'certificate_ref',
        'ruc',
        'credentials',
        'certificate',
        'required_permissions',
        'requested_at',
    ];

    private const ALLOWED_PERMISSIONS = [
        'tax_digital_certificate',
        'see_sol',
        'see_taxpayer_submission',
        'electronic_invoice',
        'contingency',
        'validity_query',
    ];

    private const LOCAL_REASON_PREFIXES = ['certificate_', 'private_key_', 'local_signature_'];

    public function __construct(
        private readonly FiscalDiagnosticsRuntimeFactory $runtimeFactory,
    ) {}

    public function probe(Request $request): JsonResponse
    {
        $maxRequestBytes = (int) config('fiscal_diagnostics.max_request_bytes', 131_072);
        $declaredLength = $request->header('Content-Length');

        if (is_string($declaredLength) && ctype_digit($declaredLength) && (int) $declaredLength > $maxRequestBytes) {
            return $this->invalidRequest('request_too_large');
        }

        if (! $request->isJson()) {
            return $this->invalidRequest('invalid_content_type');
        }

        $content = $request->getContent();

        if (! is_string($content) || $content === '') {
            return $this->invalidRequest('invalid_content');
        }

        if (strlen($content) > $maxRequestBytes) {
            return $this->invalidRequest('request_too_large');
        }

        try {
            $payload = json_decode(
                $content,
                true,
                max(2, (int) config('fiscal_diagnostics.json_depth', 6)),
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return $this->invalidRequest('invalid_json');
        }

        if (! is_array($payload)) {
            return $this->invalidRequest('invalid_json');
        }

        if (! $this->hasExactKeys($payload, self::REQUEST_KEYS)) {
            return $this->invalidRequest('unexpected_request_fields');
        }

        $tenantId = $payload['tenant_id'] ?? null;

        if (! is_string($tenantId) || preg_match(self::UUID_PATTERN, $tenantId) !== 1) {
            return $this->invalidRequest('invalid_tenant_id');
        }

        $configVersion = $payload['config_version'] ?? null;

        if (! is_int($configVersion) || $configVersion < 1 || $configVersion > 1_000_000) {
            return $this->invalidRequest('invalid_config_version');
        }

        $environment = $payload['environment'] ?? null;

        if (! is_string($environment) || ! in_array($environment, (array) config('fiscal_diagnostics.allowed_environments', []), true)) {
            return $this->invalidRequest('invalid_environment');
        }

        $solUserRef = $payload['sol_secondary_user_ref'] ?? null;

        if (! is_string($solUserRef) || preg_match(self::SOL_USER_REF_PATTERN, $solUserRef) !== 1) {
            return $this->invalidRequest('invalid_sol_secondary_user_ref');
        }

        $certificateRef = $payload['certificate_ref'] ?? null;

        if (! is_string($certificateRef) || preg_match(self::CERTIFICATE_REF_PATTERN, $certificateRef) !== 1) {
            return $this->invalidRequest('invalid_certificate_ref');
        }

        $ruc = $payload['ruc'] ?? null;

        if (! is_string($ruc) || preg_match(self::RUC_PATTERN, $ruc) !== 1) {
            return $this->invalidRequest('invalid_ruc');
        }

        $credentials = $payload['credentials'] ?? null;

        if (! is_array($credentials) || ! $this->hasExactKeys($credentials, ['sol_username', 'sol_password'])) {
            return $this->invalidRequest('invalid_credentials_structure');
        }

        $solUsername = $credentials['sol_username'] ?? null;

        if (! is_string($solUsername) || preg_match(self::SOL_USERNAME_PATTERN, $solUsername) !== 1) {
            return $this->invalidRequest('invalid_sol_username');
        }

        $solPassword = $credentials['sol_password'] ?? null;

        if (! is_string($solPassword) || $solPassword === '' || strlen($solPassword) > self::MAX_SOL_PASSWORD_LENGTH) {
            return $this->invalidRequest('invalid_sol_password');
        }

        $certificate = $payload['certificate'] ?? null;

        if (! is_array($certificate) || ! $this->hasExactKeys($certificate, ['certificate_b64', 'passphrase'])) {
            return $this->invalidRequest('invalid_certificate_structure');
        }

        $certificateB64 = $certificate['certificate_b64'] ?? null;

        if (
            ! is_string($certificateB64)
            || $certificateB64 === ''
            || strlen($certificateB64) > self::MAX_CERTIFICATE_BASE64_LENGTH
        ) {
            return $this->invalidRequest('invalid_certificate_payload');
        }

        $certificateBytes = base64_decode($certificateB64, true);

        if (
            $certificateBytes === false
            || strlen($certificateBytes) < self::MIN_CERTIFICATE_BYTES
            || strlen($certificateBytes) > (int) config('fiscal_diagnostics.max_certificate_bytes', 65_536)
        ) {
            return $this->invalidRequest('invalid_certificate_payload');
        }

        $passphrase = $certificate['passphrase'] ?? null;

        if (
            ! is_string($passphrase)
            || strlen($passphrase) > (int) config(
                'fiscal_diagnostics.max_certificate_passphrase_length',
                self::MAX_CERTIFICATE_PASSPHRASE_LENGTH,
            )
        ) {
            return $this->invalidRequest('invalid_certificate_passphrase');
        }

        $requiredPermissions = $payload['required_permissions'] ?? null;

        if (! $this->isValidPermissionList($requiredPermissions)) {
            return $this->invalidRequest('invalid_required_permissions');
        }

        $requestedAt = $payload['requested_at'] ?? null;

        if (! $this->isValidRequestedAt($requestedAt)) {
            return $this->invalidRequest('invalid_requested_at');
        }

        $tenantId = strtolower($tenantId);

        try {
            $input = new FiscalProfileDiagnosticInput(
                profileReference: $tenantId,
                configVersion: $configVersion,
                environment: $environment,
                issuerRuc: $ruc,
                solUsername: $solUsername,
                solPassword: $solPassword,
                pkcs12Contents: $certificateBytes,
                pkcs12Passphrase: $passphrase,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->invalidRequest($exception->getMessage());
        }

        try {
            $diagnostics = $this->runtimeFactory->make();
            $report = $diagnostics->diagnose($input);
        } catch (Throwable) {
            return response()->json([
                'status' => 'unavailable',
                'error' => 'fiscal_diagnostics_unavailable',
            ], 503);
        } finally {
            unset($input, $diagnostics, $solPassword, $passphrase, $certificateBytes, $certificateB64, $payload);
        }

        $technicalEvidence = $report->technicalEvidence?->toArray();

        if (! $this->isLocalDiagnosisValid($report)) {
            return response()->json([
                'status' => 'invalid_local',
                'error' => 'invalid_local',
                'tenant_id' => $report->profileReference,
                'config_version' => $report->configVersion,
                'environment' => $report->environment,
                'reasons' => $this->localFailureReasons($report->pendingReasons),
                'technical_evidence' => $technicalEvidence,
            ], 422);
        }

        $diagnosticsPayload = $report->toArray();
        unset(
            $diagnosticsPayload['external']['sunat_code'],
            $diagnosticsPayload['external']['sunat_message'],
        );

        return response()->json([
            'status' => 'pending',
            'tenant_id' => $report->profileReference,
            'config_version' => $report->configVersion,
            'environment' => $report->environment,
            'sol_secondary_user_ref' => $solUserRef,
            'certificate_ref' => $certificateRef,
            'ruc' => $ruc,
            'diagnostics' => $diagnosticsPayload,
            'technical_evidence' => $technicalEvidence,
        ]);
    }

    private function isLocalDiagnosisValid(FiscalProfileDiagnosticReport $report): bool
    {
        return $report->certificateLoaded
            && $report->privateKeyLoaded
            && $report->certificateKeyCompatible
            && $report->publicKeyAlgorithm === 'rsa'
            && $report->certificateCurrentlyValid
            && $report->localSignatureVerified;
    }

    /**
     * @param  list<string>  $pendingReasons
     * @return list<string>
     */
    private function localFailureReasons(array $pendingReasons): array
    {
        $reasons = [];

        foreach ($pendingReasons as $reason) {
            if (! is_string($reason)) {
                continue;
            }

            foreach (self::LOCAL_REASON_PREFIXES as $prefix) {
                if (str_starts_with($reason, $prefix)) {
                    $reasons[] = $reason;
                    break;
                }
            }
        }

        $reasons = array_values(array_unique($reasons));

        return $reasons === [] ? ['invalid_local_configuration'] : $reasons;
    }

    /**
     * @param  array<mixed>  $payload
     * @param  list<string>  $expectedKeys
     */
    private function hasExactKeys(array $payload, array $expectedKeys): bool
    {
        $actualKeys = array_map('strval', array_keys($payload));
        sort($actualKeys);
        sort($expectedKeys);

        return $actualKeys === $expectedKeys;
    }

    private function isValidPermissionList(mixed $requiredPermissions): bool
    {
        if (! is_array($requiredPermissions) || count($requiredPermissions) > self::MAX_PERMISSIONS) {
            return false;
        }

        $seen = [];

        foreach ($requiredPermissions as $permission) {
            if (! is_string($permission) || ! in_array($permission, self::ALLOWED_PERMISSIONS, true)) {
                return false;
            }

            if (isset($seen[$permission])) {
                return false;
            }

            $seen[$permission] = true;
        }

        return true;
    }

    private function isValidRequestedAt(mixed $requestedAt): bool
    {
        if (! is_string($requestedAt) || $requestedAt === '' || strlen($requestedAt) > self::MAX_REQUESTED_AT_LENGTH) {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $requestedAt) === 1) {
            return false;
        }

        if (preg_match(self::REQUESTED_AT_PATTERN, $requestedAt) !== 1) {
            return false;
        }

        try {
            new DateTimeImmutable($requestedAt);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function invalidRequest(string $reason): JsonResponse
    {
        return response()->json([
            'status' => 'invalid_request',
            'error' => 'invalid_request',
            'reason' => $reason,
        ], 422);
    }
}
