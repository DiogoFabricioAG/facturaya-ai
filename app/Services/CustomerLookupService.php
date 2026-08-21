<?php

namespace App\Services;

use App\Data\CustomerLookupResult;
use App\Data\RucLookupData;
use App\Models\Company;
use App\Models\SunatTaxpayer;
use App\Services\Taxpayer\RucProviderChain;

final class CustomerLookupService
{
    public function __construct(private readonly RucProviderChain $providers) {}

    public function findByRuc(Company $company, string $ruc): ?CustomerLookupResult
    {
        $saved = $company->customers()->where('ruc', $ruc)->first();
        if ($saved) {
            return new CustomerLookupResult($saved, 'saved');
        }

        $cached = SunatTaxpayer::query()->find($ruc);
        if ($cached && $cached->synced_at?->greaterThan(now()->subSeconds($this->cacheTtl()))) {
            return $this->saveCompanyCustomer($company, new RucLookupData(
                ruc: $cached->ruc,
                legalName: $cached->legal_name,
                status: $cached->status,
                condition: $cached->condition,
                address: $cached->fiscal_address,
                ubigeo: $cached->ubigeo,
                provider: $cached->provider ?: 'cache',
                asOf: $cached->as_of,
            ), 'cache');
        }

        $result = $this->providers->lookup($ruc);
        if (! $result) {
            return null;
        }

        SunatTaxpayer::query()->updateOrCreate(
            ['ruc' => $result->ruc],
            [
                'legal_name' => $result->legalName,
                'status' => $result->status,
                'condition' => $result->condition,
                'ubigeo' => $result->ubigeo,
                'fiscal_address' => $result->address,
                'provider' => $result->provider,
                'as_of' => $result->asOf,
                'synced_at' => now(),
            ],
        );

        return $this->saveCompanyCustomer($company, $result, $result->provider);
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

    private function cacheTtl(): int
    {
        return max(60, (int) config('facturaya.ruc_lookup.cache_ttl', 86_400));
    }
}
