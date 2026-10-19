<?php

namespace App\Services;

use App\Data\CompanyFiscalConfigResult;
use App\Data\CompanyProfileSnapshot;
use App\Data\CompanyProfileSyncDecision;
use App\Models\Company;
use App\Models\CompanyProfileBinding;
use App\Models\CompanyProfileSyncReceipt;
use App\Models\CompanyProfileVersion;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Persistencia y versionado atómico del perfil fiscal de una empresa.
 *
 * - `refresh()` recalcula el snapshot desde la fila `companies` y el DER
 *   público del certificado, avanza `profile_version` solo si el digest cambió
 *   e inserta la versión de auditoría. Debe invocarse tras cualquier escritura
 *   fiscal (incluida la recarga del certificado con nueva frase de paso).
 * - `bind()` persiste el vínculo tenant↔empresa con CAS e idempotencia
 *   durables, bajo bloqueo de la fila de empresa.
 * - El snapshot nunca se construye desde la request: la pertenencia se lee del
 *   binding persistido.
 */
class CompanyProfileSyncService
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const RUC_PATTERN = '/^[0-9]{11}$/';

    private const SOL_USERNAME_PATTERN = '/^[A-Za-z0-9_.-]{4,32}$/';

    private const IDEMPOTENCY_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    private const ZERO_DIGEST = '0000000000000000000000000000000000000000000000000000000000000000';

    private const MAX_SOL_PASSWORD_LENGTH = 128;

    private const MAX_PASSPHRASE_BYTES = 512;

    private const MIN_CERTIFICATE_BYTES = 64;

    private const MAX_CERTIFICATE_BYTES = 65_536;

    private const MAX_CONFIG_VERSION = 1_000_000;

    public function __construct(
        private readonly CompanyCertificateStore $certificates,
        private readonly CompanyProfileVersionService $versions = new CompanyProfileVersionService,
    ) {}

    public function bindingFor(Company $company): ?CompanyProfileBinding
    {
        return CompanyProfileBinding::query()
            ->where('company_id', $company->getKey())
            ->first();
    }

    public function snapshotFor(Company $company): ?CompanyProfileSnapshot
    {
        $der = $this->publicDerOrNull($company);

        if ($der === null) {
            return null;
        }

        return $this->buildSnapshot($company, $der, (int) $company->profile_version);
    }

    /**
     * Recalcula y persiste la versión vigente dentro de una transacción con
     * bloqueo de fila. Devuelve el snapshot aplicado o null si la empresa aún
     * no tiene credenciales/certificado utilizables.
     */
    public function refresh(Company $company): ?CompanyProfileSnapshot
    {
        return DB::transaction(function () use ($company): ?CompanyProfileSnapshot {
            $locked = Company::query()->whereKey($company->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new InvalidArgumentException('company_profile_company_not_found');
            }

            $der = $this->publicDerOrNull($locked);

            if ($der === null) {
                $company->setRawAttributes($locked->getAttributes(), true);

                return null;
            }

            $latest = CompanyProfileVersion::query()
                ->where('company_id', $locked->getKey())
                ->orderByDesc('profile_version')
                ->first();

            $digest = $this->versions->fiscalDigest(
                (string) $locked->getKey(),
                (string) $locked->ruc,
                (string) $locked->sunat_environment,
                (string) $locked->sol_user,
                hash('sha256', $der),
                (int) $locked->fiscal_revision,
            );

            if ($latest !== null && hash_equals((string) $latest->fiscal_digest, $digest)) {
                if ((int) $locked->profile_version !== (int) $latest->profile_version) {
                    $locked->forceFill(['profile_version' => (int) $latest->profile_version])->save();
                }

                $fresh = $locked->fresh();
                $company->setRawAttributes($fresh->getAttributes(), true);

                return $this->buildSnapshot($fresh, $der, (int) $latest->profile_version);
            }

            $nextVersion = $latest === null
                ? max(1, (int) $locked->profile_version)
                : max((int) $locked->profile_version, (int) $latest->profile_version) + 1;

            $snapshot = $this->buildSnapshot($locked, $der, $nextVersion);

            CompanyProfileVersion::query()->create([
                'company_id' => $locked->getKey(),
                'profile_version' => $nextVersion,
                'fiscal_digest' => $snapshot->fiscalDigest,
                'certificate_sha256' => $snapshot->certificateSha256,
                'environment' => $snapshot->environment,
                'ruc' => $snapshot->ruc,
                'created_at' => $this->now(),
            ]);

            $locked->forceFill([
                'profile_version' => $nextVersion,
                'profile_updated_at' => $this->now(),
            ])->save();

            $fresh = $locked->fresh();
            $company->setRawAttributes($fresh->getAttributes(), true);

            return $snapshot;
        });
    }

    public function bind(
        Company $company,
        string $tenantId,
        int $expectedProfileVersion,
        string $idempotencyKey,
        ?string $environment = null,
    ): CompanyProfileSyncDecision {
        if (preg_match(self::UUID_PATTERN, $tenantId) !== 1) {
            throw new InvalidArgumentException('company_profile_invalid_tenant');
        }

        if (preg_match(self::IDEMPOTENCY_PATTERN, $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('company_profile_invalid_idempotency_key');
        }

        if ($expectedProfileVersion < 0) {
            throw new InvalidArgumentException('company_profile_invalid_expected_version');
        }

        return DB::transaction(function () use (
            $company,
            $tenantId,
            $expectedProfileVersion,
            $idempotencyKey,
            $environment,
        ): CompanyProfileSyncDecision {
            $locked = Company::query()->whereKey($company->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new InvalidArgumentException('company_profile_company_not_found');
            }

            $snapshot = $this->snapshotFor($locked);

            if ($snapshot === null) {
                return CompanyProfileSyncDecision::notConfigured();
            }

            if ($environment !== null && $environment !== (string) $locked->sunat_environment) {
                return CompanyProfileSyncDecision::bindingConflict(
                    $snapshot->profileVersion,
                    $snapshot->fiscalDigest,
                    'environment_mismatch',
                );
            }

            $requestDigest = $this->versions->requestDigest($snapshot);

            $receipt = CompanyProfileSyncReceipt::query()
                ->where('company_id', $locked->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($receipt !== null) {
                if (! hash_equals((string) $receipt->request_digest, $requestDigest)) {
                    return CompanyProfileSyncDecision::idempotencyConflict(
                        $snapshot->profileVersion,
                        $snapshot->fiscalDigest,
                    );
                }

                return CompanyProfileSyncDecision::replayed(
                    (int) $receipt->profile_version,
                    (string) $receipt->fiscal_digest,
                );
            }

            $binding = CompanyProfileBinding::query()
                ->where('company_id', $locked->getKey())
                ->lockForUpdate()
                ->first();

            if ($binding !== null && ! hash_equals((string) $binding->tenant_id, strtolower($tenantId))) {
                return CompanyProfileSyncDecision::bindingConflict(
                    $snapshot->profileVersion,
                    $snapshot->fiscalDigest,
                    'company_already_bound_to_other_tenant',
                );
            }

            if ($binding === null) {
                if ($expectedProfileVersion !== 0) {
                    return CompanyProfileSyncDecision::versionConflict(0, self::ZERO_DIGEST);
                }

                $this->persistBinding($locked, $tenantId, $snapshot, 1);
                $this->recordReceipt($locked, $idempotencyKey, $requestDigest, $snapshot);

                return CompanyProfileSyncDecision::created(
                    $snapshot->profileVersion,
                    $snapshot->fiscalDigest,
                );
            }

            if (hash_equals((string) $binding->bound_digest, $snapshot->fiscalDigest)) {
                $this->recordReceipt($locked, $idempotencyKey, $requestDigest, $snapshot);

                return CompanyProfileSyncDecision::unchanged(
                    (int) $binding->bound_profile_version,
                    (string) $binding->bound_digest,
                    $expectedProfileVersion === (int) $locked->profile_version
                        ? null
                        : 'no_change_ignores_expected_version',
                );
            }

            if ($expectedProfileVersion !== (int) $locked->profile_version) {
                return CompanyProfileSyncDecision::versionConflict(
                    (int) $locked->profile_version,
                    $snapshot->fiscalDigest,
                );
            }

            $this->persistBinding($locked, $tenantId, $snapshot, (int) $binding->config_version);
            $this->recordReceipt($locked, $idempotencyKey, $requestDigest, $snapshot);

            return CompanyProfileSyncDecision::updated(
                $snapshot->profileVersion,
                $snapshot->fiscalDigest,
            );
        });
    }

    /**
     * Aplica una configuración fiscal transferida por M2M: valida el PKCS#12
     * antes de mutar, persiste SOL cifrado y PEM en ruta nueva, y actualiza
     * revisión/versión/binding/recibo en una sola transacción. El payload
     * crudo no se guarda ni se registra; solo su HMAC con `APP_KEY`.
     */
    public function applyFiscalConfig(
        Company $company,
        string $tenantId,
        int $configVersion,
        string $ruc,
        string $solUsername,
        string $solPassword,
        string $certificateContents,
        string $certificatePassphrase,
        int $expectedProfileVersion,
        string $idempotencyKey,
        string $environment,
    ): CompanyFiscalConfigResult {
        $tenant = strtolower($tenantId);

        if (preg_match(self::UUID_PATTERN, $tenantId) !== 1) {
            throw new InvalidArgumentException('company_profile_invalid_tenant');
        }

        if (preg_match(self::IDEMPOTENCY_PATTERN, $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('company_profile_invalid_idempotency_key');
        }

        if ($configVersion < 1 || $configVersion > self::MAX_CONFIG_VERSION) {
            throw new InvalidArgumentException('company_profile_invalid_config_version');
        }

        if (preg_match(self::RUC_PATTERN, $ruc) !== 1) {
            throw new InvalidArgumentException('company_profile_invalid_ruc');
        }

        if (preg_match(self::SOL_USERNAME_PATTERN, $solUsername) !== 1) {
            throw new InvalidArgumentException('company_profile_invalid_sol_username');
        }

        if ($solPassword === '' || strlen($solPassword) > self::MAX_SOL_PASSWORD_LENGTH) {
            throw new InvalidArgumentException('company_profile_invalid_sol_password');
        }

        if (strlen($certificatePassphrase) > self::MAX_PASSPHRASE_BYTES) {
            throw new InvalidArgumentException('company_profile_invalid_passphrase');
        }

        if (
            strlen($certificateContents) < self::MIN_CERTIFICATE_BYTES
            || strlen($certificateContents) > self::MAX_CERTIFICATE_BYTES
        ) {
            throw new InvalidArgumentException('company_profile_invalid_certificate');
        }

        if (! in_array($environment, ['beta', 'production'], true)) {
            throw new InvalidArgumentException('company_profile_invalid_environment');
        }

        if ($expectedProfileVersion < 0) {
            throw new InvalidArgumentException('company_profile_invalid_expected_version');
        }

        // Validación criptográfica y conversión ANTES de tocar la base.
        $pem = $this->certificates->validatedPemFromPkcs12($certificateContents, $certificatePassphrase);
        $certificateSha256 = hash('sha256', $this->certificates->publicCertificateDerFromPem($pem));
        $requestDigest = $this->fiscalRequestDigest(
            $tenant,
            $configVersion,
            $ruc,
            $solUsername,
            $solPassword,
            $certificatePassphrase,
            $certificateSha256,
            $environment,
        );

        $newCertificatePath = null;

        try {
            return DB::transaction(function () use (
                $company,
                $tenant,
                $configVersion,
                $ruc,
                $solUsername,
                $solPassword,
                $pem,
                $requestDigest,
                $expectedProfileVersion,
                $idempotencyKey,
                $environment,
                &$newCertificatePath,
            ): CompanyFiscalConfigResult {
                $locked = Company::query()->whereKey($company->getKey())->lockForUpdate()->first();

                if ($locked === null) {
                    throw new InvalidArgumentException('company_profile_company_not_found');
                }

                $binding = CompanyProfileBinding::query()
                    ->where('company_id', $locked->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($binding !== null && ! hash_equals((string) $binding->tenant_id, $tenant)) {
                    return $this->conflict($tenant, $configVersion, CompanyProfileSyncDecision::bindingConflict(
                        (int) $binding->bound_profile_version,
                        (string) $binding->bound_digest,
                        'company_already_bound_to_other_tenant',
                    ));
                }

                if ($binding !== null && $configVersion < (int) $binding->config_version) {
                    return $this->conflict($tenant, $configVersion, CompanyProfileSyncDecision::bindingConflict(
                        (int) $binding->bound_profile_version,
                        (string) $binding->bound_digest,
                        'config_version_regressed',
                    ));
                }

                if ((string) $locked->ruc !== $ruc) {
                    return $this->conflict($tenant, $configVersion, CompanyProfileSyncDecision::bindingConflict(
                        (int) $locked->profile_version,
                        self::ZERO_DIGEST,
                        'company_ruc_mismatch',
                    ));
                }

                if ((string) $locked->sunat_environment !== $environment) {
                    return $this->conflict($tenant, $configVersion, CompanyProfileSyncDecision::bindingConflict(
                        (int) $locked->profile_version,
                        self::ZERO_DIGEST,
                        'environment_mismatch',
                    ));
                }

                $receipt = CompanyProfileSyncReceipt::query()
                    ->where('company_id', $locked->getKey())
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($receipt !== null) {
                    if (! hash_equals((string) $receipt->request_digest, $requestDigest)) {
                        return $this->conflict($tenant, $configVersion, CompanyProfileSyncDecision::idempotencyConflict(
                            (int) $locked->profile_version,
                            (string) ($binding?->bound_digest ?? self::ZERO_DIGEST),
                        ));
                    }

                    $snapshot = $this->snapshotFor($locked);

                    if ($snapshot === null) {
                        return $this->conflict($tenant, $configVersion, CompanyProfileSyncDecision::notConfigured());
                    }

                    return new CompanyFiscalConfigResult(
                        status: CompanyProfileSyncDecision::REPLAYED,
                        snapshot: $snapshot,
                        configVersion: (int) ($binding?->config_version ?? $configVersion),
                        tenantId: $tenant,
                        replayed: true,
                    );
                }

                if ($binding === null) {
                    if ($expectedProfileVersion !== 0) {
                        return $this->conflict($tenant, $configVersion, CompanyProfileSyncDecision::versionConflict(0, self::ZERO_DIGEST));
                    }
                } elseif ($expectedProfileVersion !== (int) $locked->profile_version) {
                    return $this->conflict($tenant, $configVersion, CompanyProfileSyncDecision::versionConflict(
                        (int) $locked->profile_version,
                        (string) $binding->bound_digest,
                    ));
                }

                $newCertificatePath = $path = $this->certificates->storeValidatedPem($locked, $pem);

                $locked->forceFill([
                    'ruc' => $ruc,
                    'sunat_driver' => 'greenter',
                    'sunat_environment' => $environment,
                    'sol_user' => $solUsername,
                    'sol_password' => $solPassword,
                    'certificate_path' => $path,
                    'fiscal_revision' => (int) $locked->fiscal_revision + 1,
                ])->save();

                $snapshot = $this->refresh($locked);

                if ($snapshot === null) {
                    return $this->conflict($tenant, $configVersion, CompanyProfileSyncDecision::notConfigured());
                }

                $this->persistBinding($locked, $tenant, $snapshot, $configVersion);
                $this->recordReceipt($locked, $idempotencyKey, $requestDigest, $snapshot);

                return new CompanyFiscalConfigResult(
                    status: $binding === null
                        ? CompanyProfileSyncDecision::CREATED
                        : CompanyProfileSyncDecision::UPDATED,
                    snapshot: $snapshot,
                    configVersion: $configVersion,
                    tenantId: $tenant,
                );
            });
        } catch (Throwable $exception) {
            if (is_string($newCertificatePath)) {
                $this->certificates->discard($newCertificatePath);
            }

            throw $exception;
        }
    }

    private function conflict(
        string $tenantId,
        int $configVersion,
        CompanyProfileSyncDecision $decision,
    ): CompanyFiscalConfigResult {
        return new CompanyFiscalConfigResult(
            status: $decision->status,
            snapshot: null,
            configVersion: $configVersion,
            tenantId: $tenantId,
            reason: $decision->reason,
        );
    }

    private function fiscalRequestDigest(
        string $tenantId,
        int $configVersion,
        string $ruc,
        string $solUsername,
        string $solPassword,
        string $certificatePassphrase,
        string $certificateSha256,
        string $environment,
    ): string {
        $appKey = (string) config('app.key');

        if ($appKey === '') {
            throw new RuntimeException('company_profile_app_key_missing');
        }

        $canonical = json_encode([
            'certificate_sha256' => $certificateSha256,
            'config_version' => $configVersion,
            'environment' => $environment,
            'passphrase_hmac' => hash_hmac('sha256', $certificatePassphrase, $appKey),
            'ruc' => $ruc,
            'sol_password_hmac' => hash_hmac('sha256', $solPassword, $appKey),
            'sol_username' => $solUsername,
            'tenant_id' => $tenantId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', $canonical, $appKey);
    }

    private function buildSnapshot(Company $company, string $der, int $profileVersion): CompanyProfileSnapshot
    {
        return $this->versions->buildSnapshot(
            companyRef: (string) $company->getKey(),
            ruc: (string) $company->ruc,
            environment: (string) $company->sunat_environment,
            solUsername: (string) $company->sol_user,
            certificateDer: $der,
            fiscalRevision: (int) $company->fiscal_revision,
            profileVersion: max(1, $profileVersion),
            capturedAt: $this->now(),
        );
    }

    private function publicDerOrNull(Company $company): ?string
    {
        if (! $company->hasSunatCredentials() || ! filled($company->certificate_path)) {
            return null;
        }

        try {
            return $this->certificates->publicCertificateDer($company);
        } catch (RuntimeException) {
            return null;
        }
    }

    private function persistBinding(
        Company $company,
        string $tenantId,
        CompanyProfileSnapshot $snapshot,
        int $configVersion,
    ): void {
        CompanyProfileBinding::query()->updateOrCreate(
            ['company_id' => $company->getKey()],
            [
                'tenant_id' => strtolower($tenantId),
                'environment' => $snapshot->environment,
                'config_version' => $configVersion,
                'bound_profile_version' => $snapshot->profileVersion,
                'bound_digest' => $snapshot->fiscalDigest,
                'bound_at' => $this->now(),
            ],
        );
    }

    private function recordReceipt(
        Company $company,
        string $idempotencyKey,
        string $requestDigest,
        CompanyProfileSnapshot $snapshot,
    ): void {
        CompanyProfileSyncReceipt::query()->create([
            'company_id' => $company->getKey(),
            'idempotency_key' => $idempotencyKey,
            'request_digest' => $requestDigest,
            'fiscal_digest' => $snapshot->fiscalDigest,
            'profile_version' => $snapshot->profileVersion,
            'created_at' => $this->now(),
        ]);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
