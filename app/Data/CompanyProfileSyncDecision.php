<?php

namespace App\Data;

use InvalidArgumentException;

/**
 * Decisión pura de sincronización de versión de perfil.
 *
 * Códigos opacos para la plataforma: `created`, `unchanged`, `updated`,
 * `replayed`, `version_conflict` e `idempotency_conflict`. Un conflicto no
 * cambia nada y obliga a releer la fotografía vigente antes de reintentar.
 */
final readonly class CompanyProfileSyncDecision
{
    public const CREATED = 'created';

    public const UNCHANGED = 'unchanged';

    public const UPDATED = 'updated';

    public const REPLAYED = 'replayed';

    public const VERSION_CONFLICT = 'version_conflict';

    public const IDEMPOTENCY_CONFLICT = 'idempotency_conflict';

    public const BINDING_CONFLICT = 'binding_conflict';

    public const NOT_CONFIGURED = 'not_configured';

    private const STATUSES = [
        self::CREATED,
        self::UNCHANGED,
        self::UPDATED,
        self::REPLAYED,
        self::VERSION_CONFLICT,
        self::IDEMPOTENCY_CONFLICT,
        self::BINDING_CONFLICT,
        self::NOT_CONFIGURED,
    ];

    public function __construct(
        public string $status,
        public int $profileVersion,
        public string $fiscalDigest,
        public bool $replayed = false,
        public ?string $reason = null,
    ) {
        if (! in_array($this->status, self::STATUSES, true)) {
            throw new InvalidArgumentException('company_profile_invalid_sync_status');
        }

        if ($this->profileVersion < 0 || $this->profileVersion > CompanyProfileSnapshot::MAX_PROFILE_VERSION) {
            throw new InvalidArgumentException('company_profile_invalid_sync_version');
        }

        if ($this->reason !== null && preg_match('/^[a-z0-9_]{1,64}$/', $this->reason) !== 1) {
            throw new InvalidArgumentException('company_profile_invalid_sync_reason');
        }
    }

    public static function created(int $profileVersion, string $fiscalDigest): self
    {
        return new self(self::CREATED, $profileVersion, $fiscalDigest);
    }

    public static function unchanged(
        int $profileVersion,
        string $fiscalDigest,
        ?string $reason = null,
    ): self {
        return new self(self::UNCHANGED, $profileVersion, $fiscalDigest, reason: $reason);
    }

    public static function updated(int $profileVersion, string $fiscalDigest): self
    {
        return new self(self::UPDATED, $profileVersion, $fiscalDigest);
    }

    public static function replayed(int $profileVersion, string $fiscalDigest): self
    {
        return new self(self::REPLAYED, $profileVersion, $fiscalDigest, replayed: true);
    }

    public static function versionConflict(int $currentVersion, string $currentDigest): self
    {
        return new self(
            self::VERSION_CONFLICT,
            $currentVersion,
            $currentDigest,
            reason: 'expected_version_mismatch',
        );
    }

    public static function idempotencyConflict(int $currentVersion, string $currentDigest): self
    {
        return new self(
            self::IDEMPOTENCY_CONFLICT,
            $currentVersion,
            $currentDigest,
            reason: 'idempotency_key_reused_with_different_payload',
        );
    }

    public static function bindingConflict(
        int $currentVersion,
        string $currentDigest,
        string $reason,
    ): self {
        return new self(self::BINDING_CONFLICT, $currentVersion, $currentDigest, reason: $reason);
    }

    public static function notConfigured(): self
    {
        return new self(
            self::NOT_CONFIGURED,
            0,
            str_repeat('0', 64),
            reason: 'company_profile_not_configured',
        );
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'profile_version' => $this->profileVersion,
            'fiscal_digest' => $this->fiscalDigest,
            'replayed' => $this->replayed,
            'reason' => $this->reason,
        ];
    }
}
