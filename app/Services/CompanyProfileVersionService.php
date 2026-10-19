<?php

namespace App\Services;

use App\Data\CompanyProfileBinding;
use App\Data\CompanyProfileSnapshot;
use App\Data\CompanyProfileSyncDecision;
use App\Data\CompanyProfileSyncReceipt;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Lógica pura de versión y sincronización del perfil fiscal.
 *
 * Sin E/S, sin base de datos y sin leer la request: la fotografía se calcula
 * desde valores explícitos del perfil almacenado y el binding se compara contra
 * esa fotografía, no contra el eco de una petición. Lista para pruebas
 * sintéticas focalizadas.
 *
 * Seguridad del fingerprint:
 * - `certificate_sha256` se calcula sobre el **DER del certificado público**
 *   (parte X.509), nunca sobre el contenedor PKCS#12 ni sobre la contraseña.
 *   Así el valor público no depende de un secreto de baja entropía.
 * - La rotación de `certificate_password` no cambia el DER; se detecta porque
 *   el servidor incrementa `fiscal_revision` en toda escritura fiscal (incluida
 *   la recarga de un certificado con nueva frase), lo que cambia el digest e
 *   invalida la evidencia previa sin exponer ningún secreto.
 */
final class CompanyProfileVersionService
{
    public const MAX_FISCAL_REVISION = 1_000_000;

    private const SOL_USERNAME_PATTERN = '/^[A-Za-z0-9_.-]{4,32}$/';

    private const IDEMPOTENCY_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    private const ZERO_DIGEST = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private readonly DateTimeZone $timezone = new DateTimeZone('UTC'),
    ) {}

    public function buildSnapshot(
        string $companyRef,
        string $ruc,
        string $environment,
        string $solUsername,
        string $certificateDer,
        int $fiscalRevision,
        int $profileVersion,
        ?DateTimeImmutable $capturedAt = null,
    ): CompanyProfileSnapshot {
        if (preg_match(self::SOL_USERNAME_PATTERN, $solUsername) !== 1) {
            throw new InvalidArgumentException('company_profile_invalid_sol_username');
        }

        if ($certificateDer === '') {
            throw new InvalidArgumentException('company_profile_missing_certificate_der');
        }

        if ($fiscalRevision < 0 || $fiscalRevision > self::MAX_FISCAL_REVISION) {
            throw new InvalidArgumentException('company_profile_invalid_fiscal_revision');
        }

        $certificateSha256 = hash('sha256', $certificateDer);

        return new CompanyProfileSnapshot(
            companyRef: $companyRef,
            environment: $environment,
            profileVersion: $profileVersion,
            fiscalDigest: $this->fiscalDigest(
                $companyRef,
                $ruc,
                $environment,
                $solUsername,
                $certificateSha256,
                $fiscalRevision,
            ),
            certificateSha256: $certificateSha256,
            ruc: $ruc,
            solUsernameMasked: $this->maskSolUsername($solUsername),
            capturedAt: $capturedAt ?? new DateTimeImmutable('now', $this->timezone),
        );
    }

    public function fiscalDigest(
        string $companyRef,
        string $ruc,
        string $environment,
        string $solUsername,
        string $certificateSha256,
        int $fiscalRevision,
    ): string {
        $payload = json_encode([
            'certificate_sha256' => $certificateSha256,
            'company_ref' => $companyRef,
            'environment' => $environment,
            'fiscal_revision' => $fiscalRevision,
            'ruc' => $ruc,
            'sol_username' => $solUsername,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $payload);
    }

    /**
     * Huella de la carga propuesta, usada para idempotencia.
     */
    public function requestDigest(CompanyProfileSnapshot $snapshot): string
    {
        $payload = json_encode([
            'certificate_sha256' => $snapshot->certificateSha256,
            'company_ref' => $snapshot->companyRef,
            'environment' => $snapshot->environment,
            'fiscal_digest' => $snapshot->fiscalDigest,
            'ruc' => $snapshot->ruc,
            'sol_username_masked' => $snapshot->solUsernameMasked,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $payload);
    }

    public function maskSolUsername(string $solUsername): string
    {
        if (preg_match(self::SOL_USERNAME_PATTERN, $solUsername) !== 1) {
            throw new InvalidArgumentException('company_profile_invalid_sol_username');
        }

        return mb_substr($solUsername, 0, 2).'***'.mb_substr($solUsername, -1);
    }

    public function bindingFor(
        string $tenantId,
        CompanyProfileSnapshot $snapshot,
        ?DateTimeImmutable $boundAt = null,
    ): CompanyProfileBinding {
        return new CompanyProfileBinding(
            tenantId: $tenantId,
            companyRef: $snapshot->companyRef,
            environment: $snapshot->environment,
            boundProfileVersion: $snapshot->profileVersion,
            boundDigest: $snapshot->fiscalDigest,
            boundAt: $boundAt ?? new DateTimeImmutable('now', $this->timezone),
        );
    }

    public function isEvidenceCurrent(
        CompanyProfileBinding $binding,
        CompanyProfileSnapshot $current,
    ): bool {
        return $binding->matches($current);
    }

    public function syncDecision(
        ?CompanyProfileSnapshot $current,
        CompanyProfileSnapshot $incoming,
        int $expectedVersion,
        string $idempotencyKey,
        ?CompanyProfileSyncReceipt $receipt = null,
    ): CompanyProfileSyncDecision {
        if (preg_match(self::IDEMPOTENCY_PATTERN, $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('company_profile_invalid_idempotency_key');
        }

        if ($expectedVersion < 0 || $expectedVersion > CompanyProfileSnapshot::MAX_PROFILE_VERSION) {
            throw new InvalidArgumentException('company_profile_invalid_expected_version');
        }

        $requestDigest = $this->requestDigest($incoming);
        $currentVersion = $current?->profileVersion ?? 0;
        $currentDigest = $current?->fiscalDigest ?? self::ZERO_DIGEST;

        if ($receipt !== null && hash_equals($receipt->idempotencyKey, $idempotencyKey)) {
            if (! hash_equals($receipt->requestDigest, $requestDigest)) {
                return CompanyProfileSyncDecision::idempotencyConflict($currentVersion, $currentDigest);
            }

            return CompanyProfileSyncDecision::replayed(
                $receipt->profileVersion,
                $receipt->fiscalDigest,
            );
        }

        if ($current === null) {
            if ($expectedVersion !== 0) {
                return CompanyProfileSyncDecision::versionConflict(0, self::ZERO_DIGEST);
            }

            return CompanyProfileSyncDecision::created(
                $incoming->profileVersion,
                $incoming->fiscalDigest,
            );
        }

        if (hash_equals($current->fiscalDigest, $incoming->fiscalDigest)) {
            // No hay nada que mutar: un expected obsoleto no se puede "aplicar"
            // y devolver conflicto forzaría resincronizaciones inútiles. Se
            // responde unchanged y la razón explícita indica que el CAS fue
            // obviado porque el digest ya coincide.
            return CompanyProfileSyncDecision::unchanged(
                $current->profileVersion,
                $current->fiscalDigest,
                $expectedVersion === $current->profileVersion
                    ? null
                    : 'no_change_ignores_expected_version',
            );
        }

        if ($expectedVersion !== $current->profileVersion) {
            return CompanyProfileSyncDecision::versionConflict(
                $current->profileVersion,
                $current->fiscalDigest,
            );
        }

        if ($current->profileVersion >= CompanyProfileSnapshot::MAX_PROFILE_VERSION) {
            throw new InvalidArgumentException('company_profile_version_overflow');
        }

        return CompanyProfileSyncDecision::updated(
            $current->profileVersion + 1,
            $incoming->fiscalDigest,
        );
    }
}
