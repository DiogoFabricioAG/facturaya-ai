<?php

namespace App\Services\Taxpayer;

use App\Data\RucLookupData;
use App\Exceptions\RucLookupUnavailable;
use Throwable;

final class RucProviderChain
{
    public function __construct(
        private readonly ApiPeruDevProvider $apiPeru,
        private readonly OpenRucProvider $openRuc,
    ) {}

    public function lookup(string $ruc): ?RucLookupData
    {
        $lastUnavailable = null;

        foreach ([$this->apiPeru, $this->openRuc] as $provider) {
            try {
                $result = $provider->lookup($ruc);
                if ($result) {
                    return $result;
                }
            } catch (RucLookupUnavailable $exception) {
                report($exception);
                $lastUnavailable = $exception;
            } catch (Throwable $exception) {
                report($exception);
                $lastUnavailable = new RucLookupUnavailable('Un proveedor de RUC no está disponible.', previous: $exception);
            }
        }

        if ($lastUnavailable) {
            throw $lastUnavailable;
        }

        return null;
    }
}
