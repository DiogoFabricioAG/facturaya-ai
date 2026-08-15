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
];
