<?php

$enabled = in_array(env('FISCAL_DIAGNOSTICS_ENABLED', false), [true, 'true', '1', 1], true);
$allowLocalHttp = in_array(env('FISCAL_DIAGNOSTICS_ALLOW_LOCAL_HTTP', false), [true, 'true', '1', 1], true);
$consultEnabled = in_array(env('FISCAL_DIAGNOSTICS_CONSULT_ENABLED', false), [true, 'true', '1', 1], true);

return [
    /*
    |--------------------------------------------------------------------------
    | Diagnóstico fiscal M2M (endpoint interno)
    |--------------------------------------------------------------------------
    |
    | El endpoint POST /api/fiscal-setup/probe está deshabilitado por defecto.
    | Se habilita solo cuando FISCAL_DIAGNOSTICS_ENABLED es exactamente
    | true/'true'/'1'/1 (cualquier otra cadena no lo activa) y exige el token de
    | servicio dedicado FISCAL_DIAGNOSTICS_BRIDGE_TOKEN, independiente de
    | PLATFORM_ADMIN_TOKEN. Valores por entorno; nunca en Git, chat ni logs.
    |
    | allow_local_http solo permite la excepción de desarrollo con peer y host
    | loopback reales; en producción el HTTPS lo garantiza el proxy de
    | infraestructura (X-Forwarded-Proto con proxies confiados).
    |
    | consult_enabled habilita la creación del cliente SOAP de consulta CDR no
    | mutante con TLS verificado. Apagado por defecto: sin él no se construye
    | ningún cliente, no se resuelve CA y el reporte queda `pending` local.
    */

    'enabled' => $enabled,

    'allow_local_http' => $allowLocalHttp,

    'bridge_token' => env('FISCAL_DIAGNOSTICS_BRIDGE_TOKEN'),

    'max_request_bytes' => 131_072,

    'max_certificate_bytes' => 65_536,

    'max_certificate_passphrase_length' => 512,

    'json_depth' => 6,

    'allowed_environments' => ['beta', 'production'],

    'consult_enabled' => $consultEnabled,

    'consult_wsdl' => env('FISCAL_DIAGNOSTICS_CONSULT_WSDL'),

    'consult_timeout_seconds' => env('FISCAL_DIAGNOSTICS_CONSULT_TIMEOUT', 10),

    'consult_read_timeout_seconds' => env('FISCAL_DIAGNOSTICS_CONSULT_READ_TIMEOUT', 15),

    /*
    | Referencia sintáctica usada por la consulta CDR no emisora
    | (`getStatusCdr`). El operador debe acordar ambiente y referencia con
    | autoridad para consultarla antes de habilitar el cliente. F001/1 es solo
    | un default sintáctico: no demuestra que ese comprobante no exista.
    | No consultar comprobantes de terceros sin autoridad. No habilita emisión.
    */
    'consult_document_type' => env('FISCAL_DIAGNOSTICS_CONSULT_DOCUMENT_TYPE', '01'),

    'consult_series' => env('FISCAL_DIAGNOSTICS_CONSULT_SERIES', 'F001'),

    'consult_correlative' => env('FISCAL_DIAGNOSTICS_CONSULT_CORRELATIVE', 1),

    'server_ca_bundle' => env('FISCAL_DIAGNOSTICS_SERVER_CA_BUNDLE'),

    // Public issuer/profile metadata administered only by infrastructure.
    // No SUNAT issuer/OID is preselected; an empty configuration stays pending.
    // The issuer DER is base64-encoded public material, never a PKCS#12/private key.
    'identity_profile' => [
        'issuer_certificate_der_base64' => env('FISCAL_IDENTITY_ISSUER_CERT_DER_BASE64'),
        'issuer_sha256_der' => env('FISCAL_IDENTITY_ISSUER_SHA256_DER'),
        'profile_id' => env('FISCAL_IDENTITY_PROFILE_ID'),
        'policy_oid' => env('FISCAL_IDENTITY_POLICY_OID'),
        'ruc_attribute_oid' => env('FISCAL_IDENTITY_RUC_ATTRIBUTE_OID'),
        'ruc_attribute_prefix' => env('FISCAL_IDENTITY_RUC_ATTRIBUTE_PREFIX', ''),
    ],
];
