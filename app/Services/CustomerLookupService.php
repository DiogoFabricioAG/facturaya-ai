<?php

namespace App\Services;

use App\Data\CustomerLookupResult;
use App\Exceptions\SunatPadronUnavailable;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SunatTaxpayer;

final class CustomerLookupService
{
    public function findByRuc(Company $company, string $ruc): ?CustomerLookupResult
    {
        $saved = Customer::query()
            ->where('company_id', $company->id)
            ->where('ruc', $ruc)
            ->first();

        if ($saved) {
            return new CustomerLookupResult($saved, 'saved');
        }

        $taxpayer = SunatTaxpayer::query()->find($ruc);
        if (! $taxpayer) {
            if (! SunatTaxpayer::query()->exists()) {
                throw new SunatPadronUnavailable('El padrón reducido RUC todavía no fue sincronizado.');
            }

            return null;
        }

        $customer = Customer::query()->updateOrCreate(
            ['company_id' => $company->id, 'ruc' => $taxpayer->ruc],
            ['name' => $taxpayer->legal_name],
        );

        return new CustomerLookupResult(
            customer: $customer,
            source: 'sunat_padron',
            status: $taxpayer->status,
            condition: $taxpayer->condition,
        );
    }
}
