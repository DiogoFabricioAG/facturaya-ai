<?php

namespace App\Services\Taxpayer;

use App\Contracts\RucLookupProvider;
use App\Data\RucLookupData;
use App\Exceptions\RucLookupUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class OpenRucProvider implements RucLookupProvider
{
    public function lookup(string $ruc): ?RucLookupData
    {
        $baseUrl = rtrim(trim((string) config('facturaya.ruc_lookup.openruc_url')), '/');
        if ($baseUrl === '') {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout((int) config('facturaya.ruc_lookup.connect_timeout', 3))
                ->timeout((int) config('facturaya.ruc_lookup.timeout', 6))
                ->get($baseUrl.'/'.rawurlencode($ruc));
        } catch (ConnectionException $exception) {
            throw new RucLookupUnavailable('OpenRUC no respondió.', previous: $exception);
        } catch (Throwable $exception) {
            throw new RucLookupUnavailable('OpenRUC falló durante la consulta.', previous: $exception);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new RucLookupUnavailable("OpenRUC respondió con {$response->status()}.");
        }

        if ($response->status() === 404 || ! $response->successful()) {
            return null;
        }

        $data = $response->json();
        if (! is_array($data)) {
            return null;
        }

        $name = trim((string) ($data['razon_social'] ?? ''));
        if ($name === '' || (string) ($data['ruc'] ?? '') !== $ruc) {
            return null;
        }

        return new RucLookupData(
            ruc: $ruc,
            legalName: $name,
            status: $this->nullable($data['estado'] ?? null),
            condition: $this->nullable($data['condicion'] ?? null),
            address: $this->nullable($data['direccion'] ?? null),
            ubigeo: $this->nullable($data['ubigeo'] ?? null),
            provider: 'openruc',
            asOf: $this->nullable($data['as_of'] ?? null),
        );
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 1000);
    }
}
