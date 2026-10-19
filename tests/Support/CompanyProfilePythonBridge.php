<?php

declare(strict_types=1);

/**
 * Puente de aceptación entre lenguajes: Python (plataforma) -> PHP (FacturaYa).
 *
 * Lee UN único JSON por stdin y emite UN único JSON por stdout. No acepta
 * secretos por argumentos, no contacta red externa, no escribe secretos en
 * salida ni en logs. Pensado para ejecutarse dentro del contenedor aislado
 * `facturaya-ai-test` con una copia efímera del código en `/tmp` (sin `.env`,
 * sin `storage/app`, sin secretos) y variables sintéticas por `--env-file`.
 *
 * ALCANCE DE ACEPTACIÓN: las llamadas HTTP se hacen con `Request::create`
 * in-process contra el kernel de Laravel; esto acredita enrutado, middleware
 * M2M, controlador, persistencia y CAS. NO acredita verificación TLS de un
 * transporte externo real (esa capa se cubre en sus propias pruebas).
 *
 * Entrada (stdin):
 * {
 *   "company_ref": "<ULID>",
 *   "tenant_id": "<uuid>",
 *   "cross_tenant_id": "<uuid>",
 *   "fiscal_config": { payload exacto de /fiscal-config }
 * }
 *
 * Salida (stdout):
 * {
 *   "ok": true,
 *   "company_ref": "...",
 *   "fiscal_config": {"status": int, "body": {...}},
 *   "profile": {"status": int, "body": {...}},
 *   "cross_tenant": {"status": int, "body": {...}},
 *   "profile_cross_tenant": {"status": int, "body": {...}}
 * }
 *
 * En fallo: {"ok": false, "error": "<code>"} y salida 1, sin detalle interno.
 */

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

define('BRIDGE_ROOT', dirname(__DIR__, 2));

$emit = static function (array $payload, int $exitCode = 0): never {
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fwrite(STDOUT, PHP_EOL);
    exit($exitCode);
};

$fail = static function (string $code) use ($emit): never {
    $emit(['ok' => false, 'error' => $code], 1);
};

$readStdin = static function (): string {
    $raw = stream_get_contents(STDIN, 1_048_577);

    return is_string($raw) ? $raw : '';
};

/**
 * Elimina claves sensibles de las respuestas antes de exponerlas al llamador.
 *
 * @param  array<mixed>  $value
 * @return array<mixed>
 */
$redact = static function (array $value) use (&$redact): array {
    $forbidden = ['sol_user', 'sol_password', 'passphrase', 'certificate_passphrase', 'certificate_b64', 'certificate_path'];

    foreach ($value as $key => $item) {
        if (is_string($key) && in_array(strtolower($key), $forbidden, true)) {
            unset($value[$key]);

            continue;
        }

        if (is_array($item)) {
            $value[$key] = $redact($item);
        }
    }

    return $value;
};

try {
    $raw = $readStdin();

    if ($raw === '' || strlen($raw) > 1_048_576) {
        $fail('bridge_request_invalid');
    }

    try {
        $envelope = json_decode($raw, true, 6, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $fail('bridge_request_invalid');
    }

    if (! is_array($envelope)) {
        $fail('bridge_request_invalid');
    }

    $companyRef = $envelope['company_ref'] ?? null;
    $tenantId = $envelope['tenant_id'] ?? null;
    $crossTenantId = $envelope['cross_tenant_id'] ?? null;
    $fiscalConfig = $envelope['fiscal_config'] ?? null;

    $isUuid = static fn (mixed $value): bool => is_string($value)
        && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    $isUlid = static fn (mixed $value): bool => is_string($value)
        && preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $value) === 1;

    if (! $isUlid($companyRef) || ! $isUuid($tenantId) || ! $isUuid($crossTenantId) || ! is_array($fiscalConfig)) {
        $fail('bridge_request_invalid');
    }

    if (($fiscalConfig['tenant_id'] ?? null) !== $tenantId || ($fiscalConfig['ruc'] ?? null) === null) {
        $fail('bridge_request_binding_invalid');
    }

    require BRIDGE_ROOT.'/vendor/autoload.php';

    /** @var Illuminate\Foundation\Application $app */
    $app = require BRIDGE_ROOT.'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();

    // Fail-closed: nunca aceptar una copia que haya arrastrado entornos reales.
    foreach (['.env', '.env.production', '.env.local'] as $forbiddenEnv) {
        if (is_file(BRIDGE_ROOT.'/'.$forbiddenEnv)) {
            $fail('bridge_env_not_isolated');
        }
    }

    // Fail-closed de aislamiento: nunca tocar una base real ni un disco real.
    if (config('database.default') !== 'sqlite') {
        $fail('bridge_db_not_isolated');
    }

    if (config('database.connections.sqlite.database') !== ':memory:') {
        $fail('bridge_db_not_isolated');
    }

    // Storage y caché efímeros fuera del repositorio (montado en solo lectura).
    $isolatedStorage = sys_get_temp_dir().'/meowlab-profile-bridge';
    if (! is_dir($isolatedStorage) && ! mkdir($isolatedStorage, 0o777, true) && ! is_dir($isolatedStorage)) {
        $fail('bridge_storage_not_isolated');
    }
    $app->useStoragePath($isolatedStorage);

    Artisan::call('migrate', ['--force' => true]);
    Storage::fake('local');

    $company = App\Models\Company::query()->create([
        'id' => $companyRef,
        'ruc' => (string) $fiscalConfig['ruc'],
        'legal_name' => 'Empresa puente sintetica',
        'ubigeo' => '150101',
        'department' => 'LIMA',
        'province' => 'LIMA',
        'district' => 'LIMA',
        'address' => 'Av. Puente 123',
        'sunat_driver' => 'greenter',
        'sunat_environment' => 'production',
        'sol_user' => (string) ($fiscalConfig['sol_username'] ?? 'MODDATOS'),
        'sol_password' => (string) ($fiscalConfig['sol_password'] ?? 'synthetic'),
        'default_series' => 'F001',
        'default_boleta_series' => 'B001',
        'active' => true,
    ]);

    $bridgeToken = config('company_profile.bridge_token');

    if (! is_string($bridgeToken) || $bridgeToken === '') {
        $fail('bridge_token_not_configured');
    }

    $httpKernel = $app->make(HttpKernel::class);

    /**
     * @return array{status: int, body: array<mixed>}
     */
    $call = static function (string $method, string $uri, string $tenantHeader, ?string $body = null) use ($httpKernel, $bridgeToken, $redact): array {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$bridgeToken,
            'HTTP_X_MEOWLAB_TENANT_ID' => $tenantHeader,
            'REMOTE_ADDR' => '127.0.0.1',
        ];

        $request = Request::create('https://localhost'.$uri, $method, [], [], [], $server, $body);
        $response = $httpKernel->handle($request);
        $content = $response->getContent();
        $decoded = is_string($content) && $content !== '' ? json_decode($content, true) : null;

        $httpKernel->terminate($request, $response);

        return [
            'status' => $response->getStatusCode(),
            'body' => is_array($decoded) ? $redact($decoded) : [],
        ];
    };

    $fiscalBody = json_encode($fiscalConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $profileUri = '/api/internal/company-profile/'.$companyRef;
    $fiscalUri = $profileUri.'/fiscal-config';

    $fiscalResponse = $call('POST', $fiscalUri, $tenantId, $fiscalBody);
    $profileResponse = $fiscalResponse['status'] === 200
        ? $call('GET', $profileUri, $tenantId)
        : ['status' => 0, 'body' => []];

    $crossPayload = $fiscalConfig;
    $crossPayload['tenant_id'] = $crossTenantId;
    $crossPayload['expected_profile_version'] = 1;
    $crossPayload['idempotency_key'] = ((string) ($fiscalConfig['idempotency_key'] ?? 'bridge')).'.cross';

    $crossResponse = $call(
        'POST',
        $fiscalUri,
        $crossTenantId,
        json_encode($crossPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    );

    $crossProfileResponse = $call('GET', $profileUri, $crossTenantId);

    unset($fiscalConfig, $fiscalBody, $crossPayload, $company, $raw);

    $emit([
        'ok' => true,
        'company_ref' => $companyRef,
        'fiscal_config' => $fiscalResponse,
        'profile' => $profileResponse,
        'cross_tenant' => $crossResponse,
        'profile_cross_tenant' => $crossProfileResponse,
    ]);
} catch (Throwable) {
    $fail('bridge_internal_error');
}
