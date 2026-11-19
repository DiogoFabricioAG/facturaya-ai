<?php

namespace App\Services\Taxpayer;

use App\Data\RucLookupData;
use App\Data\TaxpayerLookup;
use App\Models\SunatTaxpayer;

/**
 * Padrón compartido de contribuyentes: caché `sunat_taxpayers` con vigencia y, si no
 * hay dato fresco, la cadena de proveedores. No crea clientes en ninguna empresa.
 */
final class TaxpayerDirectory
{
    public function __construct(private readonly RucProviderChain $providers) {}

    /** @throws \App\Exceptions\RucLookupUnavailable */
    public function find(string $ruc): ?TaxpayerLookup
    {
        $cached = SunatTaxpayer::query()->find($ruc);
        if ($cached && $cached->synced_at?->greaterThan(now()->subSeconds($this->cacheTtl()))) {
            return new TaxpayerLookup(new RucLookupData(
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

        return new TaxpayerLookup($result, $result->provider);
    }

    private function cacheTtl(): int
    {
        return max(60, (int) config('facturaya.ruc_lookup.cache_ttl', 86_400));
    }
}
