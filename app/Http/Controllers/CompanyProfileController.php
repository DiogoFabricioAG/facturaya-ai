<?php

namespace App\Http\Controllers;

use App\Data\CompanyProfileSyncDecision;
use App\Models\Company;
use App\Services\CompanyProfileSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Endpoint interno M2M del perfil empresarial.
 *
 * GET devuelve el snapshot vigente y el tenant persistido; la pertenencia se
 * valida contra el binding almacenado y nunca se completa con el header del
 * request. POST /sync es el gancho interno que persiste/actualiza el binding
 * con CAS e idempotencia (pendiente de confirmación de rutas con coordinación;
 * no altera la semántica del GET acordado).
 */
final class CompanyProfileController extends Controller
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const IDEMPOTENCY_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    private const MAX_SYNC_REQUEST_BYTES = 8_192;

    private const SYNC_KEYS = [
        'tenant_id',
        'expected_profile_version',
        'idempotency_key',
        'environment',
    ];

    private const MAX_FISCAL_REQUEST_BYTES = 131_072;

    private const MAX_CERTIFICATE_BASE64_LENGTH = 90_000;

    private const MIN_CERTIFICATE_BYTES = 64;

    private const MAX_CERTIFICATE_BYTES = 65_536;

    private const MAX_PASSPHRASE_BYTES = 512;

    private const MAX_SOL_PASSWORD_LENGTH = 128;

    private const RUC_PATTERN = '/^[0-9]{11}$/';

    private const SOL_USERNAME_PATTERN = '/^[A-Za-z0-9_.-]{4,32}$/';

    private const FISCAL_KEYS = [
        'tenant_id',
        'config_version',
        'ruc',
        'sol_username',
        'sol_password',
        'certificate_b64',
        'certificate_passphrase',
        'expected_profile_version',
        'idempotency_key',
        'environment',
    ];

    public function __construct(
        private readonly CompanyProfileSyncService $profiles,
    ) {}

    public function show(Request $request, Company $company): JsonResponse
    {
        $tenantHeader = $request->header('X-MeowLab-Tenant-ID');

        if (! is_string($tenantHeader) || preg_match(self::UUID_PATTERN, $tenantHeader) !== 1) {
            return response()->json(['error' => 'invalid_tenant_header'], 422);
        }

        $binding = $this->profiles->bindingFor($company);

        if ($binding === null) {
            return response()->json(['error' => 'company_profile_not_bound'], 404);
        }

        if (! hash_equals(strtolower((string) $binding->tenant_id), strtolower($tenantHeader))) {
            return response()->json(['error' => 'company_profile_not_bound'], 404);
        }

        $snapshot = $this->profiles->snapshotFor($company);

        if ($snapshot === null) {
            return response()->json(['error' => 'company_profile_not_configured'], 409);
        }

        return response()->json([
            'data' => array_merge(
                ['tenant_id' => strtolower((string) $binding->tenant_id)],
                $snapshot->toArray(),
            ),
        ]);
    }

    public function sync(Request $request, Company $company): JsonResponse
    {
        $content = $request->getContent();

        if (! is_string($content) || $content === '' || strlen($content) > self::MAX_SYNC_REQUEST_BYTES) {
            return response()->json(['error' => 'invalid_request'], 422);
        }

        try {
            $payload = json_decode($content, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['error' => 'invalid_request'], 422);
        }

        if (! is_array($payload) || ! $this->hasAllowedKeys($payload)) {
            return response()->json(['error' => 'invalid_request'], 422);
        }

        $tenantId = $payload['tenant_id'] ?? null;
        $expectedVersion = $payload['expected_profile_version'] ?? null;
        $idempotencyKey = $payload['idempotency_key'] ?? null;
        $environment = $payload['environment'] ?? null;

        if (! is_string($tenantId) || preg_match(self::UUID_PATTERN, $tenantId) !== 1) {
            return response()->json(['error' => 'invalid_tenant'], 422);
        }

        if (! is_int($expectedVersion) || $expectedVersion < 0) {
            return response()->json(['error' => 'invalid_expected_profile_version'], 422);
        }

        if (! is_string($idempotencyKey) || preg_match(self::IDEMPOTENCY_PATTERN, $idempotencyKey) !== 1) {
            return response()->json(['error' => 'invalid_idempotency_key'], 422);
        }

        if ($environment !== null && ! in_array($environment, ['beta', 'production'], true)) {
            return response()->json(['error' => 'invalid_environment'], 422);
        }

        try {
            $decision = $this->profiles->bind(
                $company,
                $tenantId,
                $expectedVersion,
                $idempotencyKey,
                is_string($environment) ? $environment : null,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => 'invalid_request',
                'reason' => $exception->getMessage(),
            ], 422);
        }

        $conflicting = in_array($decision->status, [
            CompanyProfileSyncDecision::VERSION_CONFLICT,
            CompanyProfileSyncDecision::IDEMPOTENCY_CONFLICT,
            CompanyProfileSyncDecision::BINDING_CONFLICT,
            CompanyProfileSyncDecision::NOT_CONFIGURED,
        ], true);

        return response()->json([
            'data' => array_merge([
                'company_ref' => (string) $company->getKey(),
                'tenant_id' => strtolower($tenantId),
            ], $decision->toArray()),
        ], $conflicting ? 409 : 200);
    }

    public function fiscalConfig(Request $request, Company $company): JsonResponse
    {
        $content = $request->getContent();

        if (
            ! is_string($content)
            || $content === ''
            || strlen($content) > self::MAX_FISCAL_REQUEST_BYTES
        ) {
            return response()->json(['error' => 'invalid_request'], 422);
        }

        try {
            $payload = json_decode($content, true, 3, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['error' => 'invalid_request'], 422);
        }

        if (! is_array($payload) || ! $this->hasExactKeys($payload, self::FISCAL_KEYS)) {
            return response()->json(['error' => 'invalid_request'], 422);
        }

        $tenantId = $payload['tenant_id'];
        $configVersion = $payload['config_version'];
        $ruc = $payload['ruc'];
        $solUsername = $payload['sol_username'];
        $solPassword = $payload['sol_password'];
        $certificateB64 = $payload['certificate_b64'];
        $passphrase = $payload['certificate_passphrase'];
        $expectedVersion = $payload['expected_profile_version'];
        $idempotencyKey = $payload['idempotency_key'];
        $environment = $payload['environment'];

        if (! is_string($tenantId) || preg_match(self::UUID_PATTERN, $tenantId) !== 1) {
            return response()->json(['error' => 'invalid_tenant'], 422);
        }

        if (! is_int($configVersion) || $configVersion < 1) {
            return response()->json(['error' => 'invalid_config_version'], 422);
        }

        if (! is_string($ruc) || preg_match(self::RUC_PATTERN, $ruc) !== 1) {
            return response()->json(['error' => 'invalid_ruc'], 422);
        }

        if (! is_string($solUsername) || preg_match(self::SOL_USERNAME_PATTERN, $solUsername) !== 1) {
            return response()->json(['error' => 'invalid_sol_username'], 422);
        }

        if (
            ! is_string($solPassword)
            || $solPassword === ''
            || strlen($solPassword) > self::MAX_SOL_PASSWORD_LENGTH
        ) {
            return response()->json(['error' => 'invalid_sol_password'], 422);
        }

        if (
            ! is_string($certificateB64)
            || $certificateB64 === ''
            || strlen($certificateB64) > self::MAX_CERTIFICATE_BASE64_LENGTH
        ) {
            return response()->json(['error' => 'invalid_certificate'], 422);
        }

        $certificateBytes = base64_decode($certificateB64, true);

        if (
            ! is_string($certificateBytes)
            || strlen($certificateBytes) < self::MIN_CERTIFICATE_BYTES
            || strlen($certificateBytes) > self::MAX_CERTIFICATE_BYTES
        ) {
            return response()->json(['error' => 'invalid_certificate'], 422);
        }

        if (! is_string($passphrase) || strlen($passphrase) > self::MAX_PASSPHRASE_BYTES) {
            return response()->json(['error' => 'invalid_certificate_passphrase'], 422);
        }

        if (! is_int($expectedVersion) || $expectedVersion < 0) {
            return response()->json(['error' => 'invalid_expected_profile_version'], 422);
        }

        if (! is_string($idempotencyKey) || preg_match(self::IDEMPOTENCY_PATTERN, $idempotencyKey) !== 1) {
            return response()->json(['error' => 'invalid_idempotency_key'], 422);
        }

        if (! in_array($environment, ['beta', 'production'], true)) {
            return response()->json(['error' => 'invalid_environment'], 422);
        }

        try {
            $result = $this->profiles->applyFiscalConfig(
                company: $company,
                tenantId: $tenantId,
                configVersion: $configVersion,
                ruc: $ruc,
                solUsername: $solUsername,
                solPassword: $solPassword,
                certificateContents: $certificateBytes,
                certificatePassphrase: $passphrase,
                expectedProfileVersion: $expectedVersion,
                idempotencyKey: $idempotencyKey,
                environment: $environment,
            );
        } catch (ValidationException) {
            return response()->json(['error' => 'certificate_rejected'], 422);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => 'invalid_request',
                'reason' => $exception->getMessage(),
            ], 422);
        } catch (Throwable) {
            return response()->json(['error' => 'company_profile_unavailable'], 503);
        } finally {
            unset($solPassword, $passphrase, $certificateBytes, $payload);
        }

        if ($result->isConflict()) {
            return response()->json([
                'data' => $result->conflictArray((string) $company->getKey()),
            ], 409);
        }

        return response()->json(['data' => $result->toArray()]);
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

    /**
     * @param  array<mixed>  $payload
     */
    private function hasAllowedKeys(array $payload): bool
    {
        foreach (array_keys($payload) as $key) {
            if (! is_string($key) || ! in_array($key, self::SYNC_KEYS, true)) {
                return false;
            }
        }

        return array_key_exists('tenant_id', $payload)
            && array_key_exists('expected_profile_version', $payload)
            && array_key_exists('idempotency_key', $payload);
    }
}
