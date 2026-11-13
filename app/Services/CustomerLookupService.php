<?php

namespace App\Services;

use App\Data\CustomerLookupResult;
use App\Data\RucLookupData;
use App\Models\Company;
use App\Services\Taxpayer\TaxpayerDirectory;

final class CustomerLookupService
{
    public function __construct(private readonly TaxpayerDirectory $taxpayers) {}

    public function findByRuc(Company $company, string $ruc): ?CustomerLookupResult
    {
        $saved = $company->customers()->where('ruc', $ruc)->first();
        if ($saved) {
            return new CustomerLookupResult($saved, 'saved');
        }

        $found = $this->taxpayers->find($ruc);

        return $found ? $this->saveCompanyCustomer($company, $found->data, $found->source) : null;
    }

    private function saveCompanyCustomer(Company $company, RucLookupData $data, string $source): CustomerLookupResult
    {
        $customer = $company->customers()->updateOrCreate(
            ['ruc' => $data->ruc],
            ['name' => $data->legalName],
        );

        return new CustomerLookupResult(
            customer: $customer,
            source: $source,
            provider: $data->provider,
            status: $data->status,
            condition: $data->condition,
            address: $data->address,
            ubigeo: $data->ubigeo,
        );
    }
}
