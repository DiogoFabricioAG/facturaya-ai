<?php

namespace App\Services\Taxpayer;

use App\Contracts\DniLookupProvider;
use App\Data\DniLookupData;
use App\Exceptions\DniLookupUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class ApiPeruDniProvider implements DniLookupProvider
{
    public function lookup(string $dni): ?DniLookupData
    {
        $token = trim((string) config('facturaya.dni_lookup.api_token'));
        if ($token === '') {
            throw new DniLookupUnavailable('La consulta de DNI no está configurada.');
        }

        $baseUrl = rtrim(trim((string) config('facturaya.dni_lookup.api_url')), '/');
        if ($baseUrl === '') {
            throw new DniLookupUnavailable('La URL de consulta de DNI no está configurada.');
        }

        try {
            // ApiPeru exige el token como query string. Nunca lo incluimos en logs
            // ni en la respuesta de FacturaYa.
            $response = Http::acceptJson()
                ->connectTimeout((int) config('facturaya.dni_lookup.connect_timeout', 3))
                ->timeout((int) config('facturaya.dni_lookup.timeout', 6))
                ->get($baseUrl.'/'.rawurlencode($dni), ['token' => $token]);
        } catch (ConnectionException $exception) {
            throw new DniLookupUnavailable('ApiPeru DNI no respondió.', previous: $exception);
        } catch (Throwable $exception) {
            throw new DniLookupUnavailable('ApiPeru DNI falló durante la consulta.', previous: $exception);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new DniLookupUnavailable("ApiPeru DNI respondió con {$response->status()}.");
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new DniLookupUnavailable('ApiPeru DNI rechazó las credenciales configuradas.');
        }

        if ($response->status() === 404 || ! $response->successful()) {
            return null;
        }

        $data = $response->json();
        if (! is_array($data) || ($data['success'] ?? false) !== true) {
            return null;
        }

        if ((string) ($data['dni'] ?? '') !== $dni) {
            return null;
        }

        $names = $this->value($data['nombres'] ?? null);
        $paternalSurname = $this->value($data['apellidoPaterno'] ?? null);
        $maternalSurname = $this->value($data['apellidoMaterno'] ?? null);
        $fullName = trim(implode(' ', array_filter([
            $names,
            $paternalSurname,
            $maternalSurname,
        ])));

        if ($fullName === '') {
            return null;
        }

        return new DniLookupData(
            dni: $dni,
            names: $names,
            paternalSurname: $paternalSurname,
            maternalSurname: $maternalSurname,
            fullName: $fullName,
            provider: 'api_peru_dni',
            verificationCode: $this->value($data['codVerifica'] ?? null),
            verificationLetter: $this->value($data['codVerificaLetra'] ?? null),
        );
    }

    private function value(mixed $value): string
    {
        return mb_substr(trim((string) $value), 0, 255);
    }
}
