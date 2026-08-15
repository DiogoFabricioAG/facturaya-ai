<?php

namespace App\Services\Extraction;

use App\Contracts\DocumentExtractor;
use Illuminate\Http\UploadedFile;

final class DemoDocumentExtractor implements DocumentExtractor
{
    public function extract(?string $text = null, ?UploadedFile $file = null): array
    {
        $source = match (true) {
            $text !== null && $file !== null => 'el texto y el archivo reales',
            $text !== null => 'el texto real',
            default => 'el archivo real',
        };

        return $this->example("Modo demostración: configura OPENAI_API_KEY y AI_DOCUMENT_DRIVER=openai para interpretar {$source}.");
    }

    public function extractFile(UploadedFile $file): array
    {
        return $this->extract(file: $file);
    }

    public function extractText(string $text): array
    {
        return $this->extract(text: $text);
    }

    private function example(string $warning): array
    {
        return [
            'document_type' => 'cotizacion',
            'currency' => 'PEN',
            'items' => [
                [
                    'description' => 'Servicio de diseño y desarrollo web',
                    'quantity' => 1,
                    'unit_price' => 1200,
                    'line_total' => 1200,
                    'source_page' => 1,
                    'confidence' => 0.98,
                ],
                [
                    'description' => 'Mantenimiento y soporte mensual',
                    'quantity' => 3,
                    'unit_price' => 250,
                    'line_total' => 750,
                    'source_page' => 1,
                    'confidence' => 0.93,
                ],
            ],
            'document_total' => 1950,
            'warnings' => [$warning],
        ];
    }
}
