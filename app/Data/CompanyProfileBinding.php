<?php

namespace App\Data;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Vínculo durable, gestionado por la plataforma, entre el tenant UUID y el
 * perfil fiscal de una empresa PHP.
 *
 * Persistir este registro no cambia nada en FacturaYa: es la referencia que
 * permite comparar la evidencia validada (`boundProfileVersion` +
 * `boundDigest`) contra la fotografía vigente del perfil. `boundAt` documenta
 * cuándo se capturó; no contiene tokens ni secretos.
 */
final readonly class CompanyProfileBinding
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const ULID_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/i';

    private const SHA256_PATTERN = '/^[0-9a-f]{64}$/';

    public function __construct(
        public string $tenantId,
        public string $companyRef,
        public string $environment,
        public int $boundProfileVersion,
        public string $boundDigest,
        public DateTimeImmutable $boundAt,
    ) {
        if (preg_match(self::UUID_PATTERN, $this->tenantId) !== 1) {
            throw new InvalidArgumentException('company_profile_binding_invalid_tenant');
        }

        if (preg_match(self::ULID_PATTERN, $this->companyRef) !== 1) {
            throw new InvalidArgumentException('company_profile_binding_invalid_company_ref');
        }

        if (! in_array($this->environment, CompanyProfileSnapshot::ENVIRONMENTS, true)) {
            throw new InvalidArgumentException('company_profile_binding_invalid_environment');
        }

        if (
            $this->boundProfileVersion < 1
            || $this->boundProfileVersion > CompanyProfileSnapshot::MAX_PROFILE_VERSION
        ) {
            throw new InvalidArgumentException('company_profile_binding_invalid_version');
        }

        if (preg_match(self::SHA256_PATTERN, $this->boundDigest) !== 1) {
            throw new InvalidArgumentException('company_profile_binding_invalid_digest');
        }

        if ($this->boundAt->getTimezone() === false) {
            throw new InvalidArgumentException('company_profile_binding_invalid_time');
        }
    }

    /**
     * Binding independiente: la evidencia sigue vigente solo si empresa,
     * ambiente, versión y digest coinciden exactamente con la fotografía.
     */
    public function matches(CompanyProfileSnapshot $snapshot): bool
    {
        return $this->companyRef === $snapshot->companyRef
            && $this->environment === $snapshot->environment
            && $this->boundProfileVersion === $snapshot->profileVersion
            && $this->boundDigest === $snapshot->fiscalDigest;
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'company_ref' => $this->companyRef,
            'environment' => $this->environment,
            'bound_profile_version' => $this->boundProfileVersion,
            'bound_digest' => $this->boundDigest,
            'bound_at' => $this->boundAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
        ];
    }
}
