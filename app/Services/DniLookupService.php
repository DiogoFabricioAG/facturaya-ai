<?php

namespace App\Services;

use App\Data\DniLookupResult;
use App\Models\Company;
use App\Services\Taxpayer\ApiPeruDniProvider;

final class DniLookupService
{
    public function __construct(private readonly ApiPeruDniProvider $provider) {}

    public function findByDni(Company $company, string $dni): ?DniLookupResult
    {
        $saved = $company->customers()
            ->where('document_type', '1')
            ->where('ruc', $dni)
            ->first();

        if ($saved) {
            return new DniLookupResult($saved, 'saved');
        }

        $data = $this->provider->lookup($dni);
        if (! $data) {
            return null;
        }

        $customer = $company->customers()->updateOrCreate(
            [
                'document_type' => '1',
                'ruc' => $data->dni,
            ],
            ['name' => $data->fullName],
        );

        return new DniLookupResult($customer, $data->provider, $data);
    }
}
