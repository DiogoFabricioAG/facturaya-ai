<?php

namespace App\Services;

use App\Models\Company;
use LogicException;

final class CompanyContext
{
    private ?Company $company = null;

    public function set(Company $company): void
    {
        $this->company = $company;
    }

    public function company(): Company
    {
        return $this->company ?? throw new LogicException('No hay una empresa autenticada en esta petición.');
    }

    public function owns(?string $companyId): bool
    {
        return $companyId !== null && $this->company()->getKey() === $companyId;
    }
}
