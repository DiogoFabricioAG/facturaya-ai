<?php

namespace App\Services\Sunat;

use App\Contracts\SunatGateway;
use App\Models\Company;
use InvalidArgumentException;

final class SunatGatewayManager
{
    public function __construct(
        private readonly FakeSunatGateway $fake,
        private readonly GreenterSunatGateway $greenter,
    ) {}

    public function for(Company $company): SunatGateway
    {
        return match ($company->sunat_driver) {
            'fake' => $this->fake,
            'greenter' => $this->greenter,
            default => throw new InvalidArgumentException('La empresa tiene un driver SUNAT no soportado.'),
        };
    }
}
