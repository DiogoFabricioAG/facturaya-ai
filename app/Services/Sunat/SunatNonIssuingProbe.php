<?php

namespace App\Services\Sunat;

use App\Data\FiscalExternalProbeOutcome;
use App\Data\FiscalProfileDiagnosticInput;

/**
 * Puerto de comprobación externa no emisora.
 *
 * Las implementaciones solo pueden usar operaciones de consulta oficiales
 * (por ejemplo `getStatusCdr`) y nunca envío de comprobantes, resúmenes ni
 * cualquier operación que produzca efectos tributarios.
 */
interface SunatNonIssuingProbe
{
    public function probe(FiscalProfileDiagnosticInput $input): FiscalExternalProbeOutcome;
}
