<?php

return [
    'platform' => [
        'admin_token' => env('PLATFORM_ADMIN_TOKEN'),
    ],

    'ai' => [
        'driver' => env('AI_DOCUMENT_DRIVER', 'demo'),
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-5.4'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'timeout' => (int) env('OPENAI_TIMEOUT', 120),
            'ca_bundle' => env('OPENAI_CA_BUNDLE'),
        ],
    ],

    'sunat' => [
        'default_driver' => env('SUNAT_DEFAULT_DRIVER', 'fake'),
        'default_environment' => env('SUNAT_DEFAULT_ENVIRONMENT', 'beta'),
    ],

    'ruc_lookup' => [
        'api_peru_url' => env('RUC_LOOKUP_API_PERU_URL', 'https://api.apiperu.dev/ruc'),
        'api_peru_token' => env('RUC_LOOKUP_API_PERU_TOKEN'),
        'openruc_url' => env('RUC_LOOKUP_OPENRUC_URL', 'https://openruc.com/api/ruc'),
        'connect_timeout' => (int) env('RUC_LOOKUP_CONNECT_TIMEOUT', 3),
        'timeout' => (int) env('RUC_LOOKUP_TIMEOUT', 6),
        'cache_ttl' => (int) env('RUC_LOOKUP_CACHE_TTL', 86400),
    ],

    'dni_lookup' => [
        'api_url' => env('DNI_LOOKUP_API_URL', 'https://dniruc.apisperu.com/api/v1/dni'),
        'api_token' => env('DNI_LOOKUP_API_TOKEN'),
        'connect_timeout' => (int) env('DNI_LOOKUP_CONNECT_TIMEOUT', 3),
        'timeout' => (int) env('DNI_LOOKUP_TIMEOUT', 6),
    ],
];
