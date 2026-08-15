<?php

namespace App\Contracts;

use Illuminate\Http\UploadedFile;

interface DocumentExtractor
{
    /**
     * @return array{
     *   document_type: string,
     *   currency: string,
     *   items: array<int, array<string, mixed>>,
     *   document_total: float|null,
     *   warnings: array<int, string>
     * }
     */
    public function extract(?string $text = null, ?UploadedFile $file = null): array;

    /**
     * @return array{
     *   document_type: string,
     *   currency: string,
     *   items: array<int, array<string, mixed>>,
     *   document_total: float|null,
     *   warnings: array<int, string>
     * }
     */
    public function extractFile(UploadedFile $file): array;

    /**
     * @return array{
     *   document_type: string,
     *   currency: string,
     *   items: array<int, array<string, mixed>>,
     *   document_total: float|null,
     *   warnings: array<int, string>
     * }
     */
    public function extractText(string $text): array;
}
