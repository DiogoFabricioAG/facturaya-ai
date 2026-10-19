<?php

namespace App\Services\Sunat;

use App\Data\CertificateIdentityMatchResult;

/**
 * Puerto de correspondencia de identidad del certificado emisor.
 *
 * Las implementaciones confiables viven en el servidor, se asocian a un perfil
 * CA/OIDs documentados y reciben únicamente material público (PEM del
 * certificado). El diagnóstico no incluye implementación por defecto ni
 * heurísticas de subject/CN: sin matcher inyectado la identidad del certificado
 * queda `pending` y no autoriza nada. Un resultado `match` acredita la
 * correspondencia con el perfil configurado, no la aceptación de SUNAT.
 */
interface CertificateIdentityMatcher
{
    public function match(string $certificatePem, string $expectedRuc): CertificateIdentityMatchResult;
}
