<?php

namespace App\Services\Taxpayer;

use App\Contracts\RucLookupProvider;
use App\Data\RucLookupData;
use App\Exceptions\RucLookupUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class ApiPeruDevProvider implements RucLookupProvider
{
    public function lookup(string $ruc): ?RucLookupData
    {
        $token = trim((string) config('facturaya.ruc_lookup.api_peru_token'));
        if ($token === '') {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->connectTimeout((int) config('facturaya.ruc_lookup.connect_timeout', 3))
                ->timeout((int) config('facturaya.ruc_lookup.timeout', 6))
                ->post((string) config('facturaya.ruc_lookup.api_peru_url'), ['ruc' => $ruc]);
        } catch (ConnectionException $exception) {
            throw new RucLookupUnavailable('ApiPeruDev no respondió.', previous: $exception);
        } catch (Throwable $exception) {
            throw new RucLookupUnavailable('ApiPeruDev falló durante la consulta.', previous: $exception);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new RucLookupUnavailable("ApiPeruDev respondió con {$response->status()}.");
        }

        if ($response->status() === 404 || ! $response->successful()) {
            return null;
        }

        $payload = $response->json();
        if (! is_array($payload) || ($payload['success'] ?? false) !== true || ! is_array($payload['data'] ?? null)) {
            return null;
        }

        $data = $payload['data'];
        $name = trim((string) ($data['nombre_o_razon_social'] ?? ''));
        if ($name === '' || (string) ($data['ruc'] ?? '') !== $ruc) {
            return null;
        }

        return new RucLookupData(
            ruc: $ruc,
            legalName: $name,
            status: $this->nullable($data['estado'] ?? null),
            condition: $this->nullable($data['condicion'] ?? null),
            address: $this->nullable($data['direccion_completa'] ?? $data['direccion'] ?? null),
            ubigeo: $this->nullable($data['ubigeo_sunat'] ?? null),
            provider: 'api_peru',
            asOf: null,
        );
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 1000);
    }
}
