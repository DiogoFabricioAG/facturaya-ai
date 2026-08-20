<?php

namespace App\Console\Commands;

use App\Services\SunatRucPadronImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SyncSunatRucPadron extends Command
{
    protected $signature = 'sunat:sync-ruc
        {--url=https://www2.sunat.gob.pe/padron_reducido_ruc.zip : URL oficial del padrón reducido RUC}
        {--chunk=1000 : Registros por lote}
        {--force : Descarga aunque SUNAT conserve el mismo ETag}';

    protected $description = 'Descarga e importa el padrón reducido RUC oficial de SUNAT';

    public function handle(SunatRucPadronImporter $importer): int
    {
        $url = trim((string) $this->option('url'));
        $etagKey = 'sunat:ruc-padron:etag';
        File::ensureDirectoryExists(storage_path('app/private'));
        $temporary = tempnam(storage_path('app/private'), 'sunat-ruc-');

        if ($url === '' || $temporary === false) {
            $this->error('No se pudo preparar la sincronización del padrón RUC.');

            return self::FAILURE;
        }

        try {
            $head = Http::connectTimeout(10)->timeout(30)->head($url);
            $etag = trim((string) $head->header('ETag'));
            if (! $this->option('force') && $etag !== '' && Cache::get($etagKey) === $etag) {
                $this->info('El padrón RUC de SUNAT no cambió; no se descargó nuevamente.');

                return self::SUCCESS;
            }

            $this->info('Descargando el padrón reducido RUC de SUNAT...');
            $response = Http::connectTimeout(15)
                ->timeout(1800)
                ->withOptions(['sink' => $temporary])
                ->get($url);

            if (! $response->successful()) {
                $this->error("SUNAT respondió con estado {$response->status()}.");

                return self::FAILURE;
            }

            $this->info('Importando contribuyentes en lotes...');
            $processed = $importer->import($temporary, (int) $this->option('chunk'));
            if ($etag !== '') {
                Cache::forever($etagKey, $etag);
            }

            $this->info(number_format($processed).' contribuyentes sincronizados.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
