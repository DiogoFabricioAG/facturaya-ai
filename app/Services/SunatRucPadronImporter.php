<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

final class SunatRucPadronImporter
{
    public function import(string $zipPath, int $chunkSize = 1000): int
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('SUNAT no devolvió un ZIP válido.');
        }

        try {
            $entry = $this->findTextEntry($zip);
            $stream = $zip->getStream($entry);
            if (! is_resource($stream)) {
                throw new RuntimeException('No se pudo leer el padrón RUC dentro del ZIP.');
            }

            try {
                return $this->importStream($stream, max($chunkSize, 100));
            } finally {
                fclose($stream);
            }
        } finally {
            $zip->close();
        }
    }

    /** @param resource $stream */
    private function importStream($stream, int $chunkSize): int
    {
        $rows = [];
        $processed = 0;
        $syncedAt = now();

        while (($rawLine = fgets($stream)) !== false) {
            $line = $this->utf8(trim($rawLine));
            if ($line === '') {
                continue;
            }

            $columns = array_map('trim', explode('|', $line));
            $ruc = preg_replace('/\D/', '', $columns[0] ?? '');
            $legalName = trim($columns[1] ?? '');

            if (! preg_match('/^\d{11}$/', $ruc) || $legalName === '') {
                continue;
            }

            $rows[] = [
                'ruc' => $ruc,
                'legal_name' => mb_substr($legalName, 0, 255),
                'status' => $this->nullable($columns[2] ?? null, 60),
                'condition' => $this->nullable($columns[3] ?? null, 60),
                'ubigeo' => $this->nullable($columns[4] ?? null, 6),
                'fiscal_address' => $this->nullable($columns[5] ?? null, 1000),
                'synced_at' => $syncedAt,
            ];

            if (count($rows) >= $chunkSize) {
                $processed += $this->upsert($rows);
                $rows = [];
            }
        }

        return $processed + $this->upsert($rows);
    }

    private function findTextEntry(ZipArchive $zip): string
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (is_string($name) && preg_match('/\.(?:txt|csv)$/i', $name)) {
                return $name;
            }
        }

        throw new RuntimeException('El ZIP de SUNAT no contiene el padrón esperado.');
    }

    private function upsert(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        DB::table('sunat_taxpayers')->upsert(
            $rows,
            ['ruc'],
            ['legal_name', 'status', 'condition', 'ubigeo', 'fiscal_address', 'synced_at'],
        );

        return count($rows);
    }

    private function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8')
            ? $value
            : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    private function nullable(mixed $value, int $max): ?string
    {
        $text = trim((string) $value);

        return $text === '' || $text === '-' ? null : mb_substr($text, 0, $max);
    }
}
