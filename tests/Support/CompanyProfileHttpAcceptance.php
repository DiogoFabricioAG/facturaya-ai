<?php

declare(strict_types=1);

/**
 * Preparación aislada del servidor HTTP de aceptación Python -> Laravel PHP.
 *
 * Uso: php tests/Support/CompanyProfileHttpAcceptance.php --prepare
 *
 * Se ejecuta dentro del contenedor `facturaya-ai-test` sobre una copia efímera
 * del código en /tmp (sin `.env` ni `storage/app`): migra una SQLite temporal
 * bajo /tmp y crea UNA empresa sintética con RUC/ULID tomados del entorno
 * (`--env-file`), nunca de argumentos. No imprime secretos ni datos de
 * credenciales; falla cerrado con códigos opacos.
 */

use App\Models\Company;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;

define('HTTP_ACCEPTANCE_ROOT', dirname(__DIR__, 2));

$emit = static function (array $payload, int $exitCode = 0): never {
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES));
    fwrite(STDOUT, PHP_EOL);
    exit($exitCode);
};

$fail = static function (string $code) use ($emit): never {
    $emit(['ok' => false, 'error' => $code], 1);
};

try {
    if (($argv[1] ?? null) !== '--prepare') {
        $fail('http_acceptance_mode_invalid');
    }

    require HTTP_ACCEPTANCE_ROOT.'/vendor/autoload.php';

    /** @var Illuminate\Foundation\Application $app */
    $app = require HTTP_ACCEPTANCE_ROOT.'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();

    foreach (['.env', '.env.production', '.env.local'] as $envFile) {
        if (is_file(HTTP_ACCEPTANCE_ROOT.'/'.$envFile)) {
            $fail('http_acceptance_env_not_isolated');
        }
    }

    if (config('database.default') !== 'sqlite') {
        $fail('http_acceptance_db_not_isolated');
    }

    $database = config('database.connections.sqlite.database');

    if (! is_string($database) || ! str_starts_with($database, '/tmp/')) {
        $fail('http_acceptance_db_not_isolated');
    }

    Artisan::call('migrate', ['--force' => true]);

    $companyRef = getenv('BRIDGE_COMPANY_REF');
    $tenantId = getenv('BRIDGE_TENANT_ID');
    $ruc = getenv('BRIDGE_RUC');
    $solUser = getenv('BRIDGE_SOL_USER');
    $solPassword = getenv('BRIDGE_SOL_PASSWORD');

    if (! is_string($companyRef) || preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $companyRef) !== 1) {
        $fail('http_acceptance_company_invalid');
    }

    if (! is_string($tenantId) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId) !== 1) {
        $fail('http_acceptance_tenant_invalid');
    }

    if (! is_string($ruc) || preg_match('/^[0-9]{11}$/', $ruc) !== 1) {
        $fail('http_acceptance_ruc_invalid');
    }

    if (! is_string($solUser) || preg_match('/^[A-Za-z0-9_.-]{4,32}$/', $solUser) !== 1) {
        $fail('http_acceptance_sol_user_invalid');
    }

    if (! is_string($solPassword) || $solPassword === '' || strlen($solPassword) > 128) {
        $fail('http_acceptance_sol_password_invalid');
    }

    if (Company::query()->whereKey($companyRef)->exists()) {
        $fail('http_acceptance_company_exists');
    }

    Company::query()->create([
        'id' => $companyRef,
        'ruc' => $ruc,
        'legal_name' => 'Empresa aceptacion https',
        'ubigeo' => '150101',
        'department' => 'LIMA',
        'province' => 'LIMA',
        'district' => 'LIMA',
        'address' => 'Av. Aceptacion 123',
        'sunat_driver' => 'greenter',
        'sunat_environment' => 'production',
        'sol_user' => $solUser,
        'sol_password' => $solPassword,
        'default_series' => 'F001',
        'default_boleta_series' => 'B001',
        'active' => true,
    ]);

    $emit(['ok' => true]);
} catch (Throwable) {
    $fail('http_acceptance_internal_error');
}
