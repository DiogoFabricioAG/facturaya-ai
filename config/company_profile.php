<?php

$enabled = in_array(env('FISCAL_PROFILE_ENABLED', false), [true, 'true', '1', 1], true);
$allowLocalHttp = in_array(env('FISCAL_PROFILE_ALLOW_LOCAL_HTTP', false), [true, 'true', '1', 1], true);

return [
    /*
    |--------------------------------------------------------------------------
    | Perfil empresarial M2M (endpoint interno)
    |--------------------------------------------------------------------------
    |
    | GET /api/internal/company-profile/{company} está protegido con un bearer
    | dedicado (FISCAL_PROFILE_BRIDGE_TOKEN) y el header X-MeowLab-Tenant-ID.
    | Deshabilitado por defecto; la pertenencia se valida contra el binding
    | persistido, nunca contra el request. Valores por entorno, nunca en Git.
    */

    'enabled' => $enabled,

    'allow_local_http' => $allowLocalHttp,

    'bridge_token' => env('FISCAL_PROFILE_BRIDGE_TOKEN'),
];
