<?php

namespace App\Data;

/**
 * Resultado del POST interno de configuración fiscal.
 *
 * En éxito, `toArray()` devuelve exactamente el snapshot del GET más
 * `config_version` aplicado y el `status` de la operación. En conflicto,
 * `snapshot` es null y solo se expone estado/razón opacos.
 */
final readonly class CompanyFiscalConfigResult
{
    public function __construct(
        public string $status,
        public ?CompanyProfileSnapshot $snapshot,
        public int $configVersion,
        public string $tenantId,
        public ?string $reason = null,
        public bool $replayed = false,
    ) {}

    public function isConflict(): bool
    {
        return $this->snapshot === null;
    }

    /**
     * @return array<string, int|string|bool>
     */
    public function toArray(): array
    {
        return array_merge(
            ['tenant_id' => $this->tenantId],
            $this->snapshot?->toArray() ?? [],
            [
                'config_version' => $this->configVersion,
                'status' => $this->status,
                'replayed' => $this->replayed,
            ],
        );
    }

    /**
     * @return array<string, int|string|null>
     */
    public function conflictArray(string $companyRef): array
    {
        return [
            'company_ref' => $companyRef,
            'tenant_id' => $this->tenantId,
            'status' => $this->status,
            'reason' => $this->reason,
        ];
    }
}
