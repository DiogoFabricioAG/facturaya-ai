<?php

namespace App\Services;

use App\Models\CreditNote;

final class CreditNotePdfService
{
    private const PAGE_WIDTH = 595;

    private const PAGE_HEIGHT = 842;

    private const BLACK = [0.0, 0.0, 0.0];

    private const GRAY = [0.35, 0.35, 0.35];

    private const LINE = [0.65, 0.65, 0.65];

    public function render(CreditNote $creditNote): string
    {
        $creditNote->loadMissing(['company', 'invoice.draft', 'items']);

        $company = $creditNote->company;
        $invoice = $creditNote->invoice;
        $draft = $invoice?->draft;

        $customerName = $draft ? trim((string) $draft->customer_name) : 'Cliente';
        $customerRuc = $draft ? (string) $draft->customer_ruc : '-';
        $customerDocLabel = ($draft?->customer_document_type === '1') ? 'DNI' : 'RUC';

        $data = [
            'number' => $creditNote->number,
            'document_type' => '07',
            'document_name' => 'NOTA DE CREDITO ELECTRONICA',
            'series' => (string) $creditNote->series,
            'status' => (string) $creditNote->status,
            'origin_number' => (string) ($invoice?->number ?: '-'),
            'reason_code' => (string) $creditNote->reason_code,
            'reason_description' => (string) $creditNote->reason_description,
            'company_name' => trim((string) $company->legal_name) ?: 'Empresa emisora',
            'company_ruc' => (string) $company->ruc,
            'company_address' => $this->address($company),
            'customer_name' => $customerName ?: 'Cliente',
            'customer_ruc' => $customerRuc ?: '-',
            'customer_document_label' => $customerDocLabel,
            'issue_date' => optional($creditNote->issue_date)->format('d/m/Y') ?: '-',
            'currency' => (string) ($creditNote->currency ?: 'PEN'),
            'currency_label' => ($creditNote->currency ?: 'PEN') === 'PEN' ? 'SOLES' : (string) ($creditNote->currency ?: 'PEN'),
            'subtotal' => $this->money($creditNote->subtotal),
            'igv' => $this->money($creditNote->igv),
            'total' => $this->money($creditNote->total),
            'items' => $creditNote->items->map(fn ($item): array => [
                'description' => trim((string) $item->description) ?: 'Concepto',
                'quantity' => number_format((float) $item->quantity, 3, '.', ''),
                'unit_price' => $this->money($item->unit_price_with_igv ?: $item->entered_unit_price),
                'total' => $this->money($item->line_total),
            ])->values()->all(),
        ];

        return $this->makePdf($data);
    }

    private function money(mixed $value): string
    {
        return 'S/ '.number_format((float) $value, 2, '.', ',');
    }

    private function address(object $company): string
    {
        return trim(implode(', ', array_filter([
            $company->address ?? '',
            $company->district ?? '',
            $company->province ?? '',
            $company->department ?? '',
        ], static fn ($value): bool => trim((string) $value) !== '')));
    }

    /** @param array<string, mixed> $data */
    private function makePdf(array $data): string
    {
        $chunks = array_chunk($data['items'], 10);
        $chunks = $chunks === [] ? [[]] : $chunks;
        $pages = [];

        foreach ($chunks as $index => $items) {
            $commands = [];
            $lastPage = $index === count($chunks) - 1;
            $tableTop = $this->drawHeader($commands, $data, $index === 0);
            $cursor = $this->drawItemsTable($commands, $items, $tableTop);

            if ($lastPage) {
                $this->drawTotals($commands, $data, $cursor);
            } else {
                $this->text($commands, 42, 74, 'Continua en la siguiente pagina', 8, '/F1', self::GRAY);
            }

            $this->drawFooter($commands, $index + 1, count($chunks));
            $pages[] = implode("\n", $commands);
        }

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $pageReferences = [];

        foreach ($pages as $index => $stream) {
            $pageObject = 5 + ($index * 2);
            $contentObject = $pageObject + 1;
            $pageReferences[] = $pageObject.' 0 R';
            $objects[$pageObject] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $contentObject,
            );
            $objects[$contentObject] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $pageReferences).'] /Count '.count($pages).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        $lastObject = max(array_keys($objects));

        for ($number = 1; $number <= $lastObject; $number++) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number." 0 obj\n".$objects[$number]."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".($lastObject + 1)."\n0000000000 65535 f \n";

        for ($number = 1; $number <= $lastObject; $number++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }

        $pdf .= "trailer\n<< /Size ".($lastObject + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";

        return $pdf;
    }

    /** @param array<int, string> $commands @param array<string, mixed> $data */
    private function drawHeader(array &$commands, array $data, bool $firstPage): int
    {
        if (! $firstPage) {
            $this->text($commands, 42, 790, 'DETALLE DE LA NOTA - CONTINUACION', 9, '/F2', self::BLACK);

            return 760;
        }

        $this->centerText($commands, self::PAGE_WIDTH / 2, 803, 'NOTA DE CREDITO ELECTRONICA', 16, '/F2', self::BLACK);
        $this->line($commands, 42, 792, 553, 792, self::BLACK, 0.8);

        $this->text($commands, 42, 770, 'DATOS DEL COMPROBANTE:', 9, '/F2', self::BLACK);
        $this->text($commands, 50, 750, 'NUMERO: '.$data['number'], 9, '/F1', self::BLACK);
        $this->text($commands, 305, 750, 'DOC. AFECTADO: '.$data['origin_number'], 9, '/F1', self::BLACK);
        $this->text($commands, 50, 733, 'FECHA DE EMISION: '.$data['issue_date'], 9, '/F1', self::BLACK);
        $this->text($commands, 305, 733, 'MOTIVO: '.$this->shortText($data['reason_description'], 40), 9, '/F1', self::BLACK);

        $this->text($commands, 42, 705, 'DATOS DEL EMISOR:', 9, '/F2', self::BLACK);
        $this->text($commands, 50, 686, 'RUC: '.$data['company_ruc'], 9, '/F1', self::BLACK);
        $this->text($commands, 50, 669, 'RAZON SOCIAL: '.$this->shortText($data['company_name'], 86), 9, '/F1', self::BLACK);
        $this->text($commands, 50, 652, 'DOMICILIO FISCAL: '.$this->shortText($data['company_address'] ?: 'No registrado', 82), 9, '/F1', self::BLACK);

        $this->text($commands, 42, 624, 'DATOS DEL CLIENTE:', 9, '/F2', self::BLACK);
        $this->text($commands, 50, 605, 'TIPO DE DOCUMENTO: '.$data['customer_document_label'], 9, '/F1', self::BLACK);
        $this->text($commands, 305, 605, 'NUMERO: '.$data['customer_ruc'], 9, '/F1', self::BLACK);
        $this->text($commands, 50, 588, 'NOMBRE / RAZON SOCIAL: '.$this->shortText($data['customer_name'], 74), 9, '/F1', self::BLACK);

        $this->text($commands, 42, 559, 'DETALLE:', 9, '/F2', self::BLACK);

        return 542;
    }

    /** @param array<int, string> $commands @param array<int, array<string, string>> $items */
    private function drawItemsTable(array &$commands, array $items, int $top): int
    {
        $headerBottom = $top - 24;
        $this->strokeRect($commands, 42, $headerBottom, 511, 24, self::BLACK, 0.7);
        $this->line($commands, 98, $headerBottom, 98, $top, self::LINE, 0.6);
        $this->line($commands, 410, $headerBottom, 410, $top, self::LINE, 0.6);
        $this->line($commands, 480, $headerBottom, 480, $top, self::LINE, 0.6);
        $this->text($commands, 53, $top - 16, 'CANT.', 8, '/F2', self::BLACK);
        $this->text($commands, 112, $top - 16, 'DESCRIPCION', 8, '/F2', self::BLACK);
        $this->text($commands, 420, $top - 16, 'P. UNIT.', 8, '/F2', self::BLACK);
        $this->text($commands, 490, $top - 16, 'IMPORTE', 8, '/F2', self::BLACK);

        $cursor = $headerBottom - 18;

        foreach ($items as $item) {
            $this->text($commands, 50, $cursor, $item['quantity'], 8, '/F1', self::BLACK);
            $this->text($commands, 105, $cursor, $this->shortText($item['description'], 50), 8, '/F1', self::BLACK);
            $this->rightText($commands, 470, $cursor, $item['unit_price'], 8, '/F1', self::BLACK);
            $this->rightText($commands, 545, $cursor, $item['total'], 8, '/F1', self::BLACK);
            $cursor -= 18;
        }

        return $cursor;
    }

    /** @param array<int, string> $commands @param array<string, mixed> $data */
    private function drawTotals(array &$commands, array $data, int $cursor): void
    {
        $boxTop = max($cursor - 10, 140);
        $this->line($commands, 350, $boxTop, 553, $boxTop, self::BLACK, 0.8);
        $this->text($commands, 360, $boxTop - 18, 'OP. GRAVADA:', 9, '/F2', self::BLACK);
        $this->rightText($commands, 545, $boxTop - 18, $data['subtotal'], 9, '/F1', self::BLACK);
        $this->text($commands, 360, $boxTop - 36, 'I.G.V. (18%):', 9, '/F2', self::BLACK);
        $this->rightText($commands, 545, $boxTop - 36, $data['igv'], 9, '/F1', self::BLACK);
        $this->text($commands, 360, $boxTop - 54, 'IMPORTE TOTAL:', 9, '/F2', self::BLACK);
        $this->rightText($commands, 545, $boxTop - 54, $data['total'], 9, '/F2', self::BLACK);
    }

    /** @param array<int, string> $commands */
    private function drawFooter(array &$commands, int $page, int $total): void
    {
        $this->line($commands, 42, 45, 553, 45, self::LINE, 0.6);
        $this->text($commands, 42, 32, 'Representacion impresa de la Nota de Credito Electronica.', 8, '/F1', self::GRAY);
        $this->rightText($commands, 553, 32, sprintf('Pagina %d de %d', $page, $total), 8, '/F1', self::GRAY);
    }

    /**
     * @param array<int, string> $commands
     * @param array<int, float> $color
     */
    private function text(array &$commands, float $x, float $y, string $text, int $size, string $font, array $color): void
    {
        $commands[] = sprintf('%.2f %.2f %.2f rg', $color[0], $color[1], $color[2]);
        $commands[] = 'BT';
        $commands[] = sprintf('%s %d Tf', $font, $size);
        $commands[] = sprintf('%.2f %.2f Td', $x, $y);
        $commands[] = sprintf('(%s) Tj', $this->escapeText($text));
        $commands[] = 'ET';
    }

    /**
     * @param array<int, string> $commands
     * @param array<int, float> $color
     */
    private function centerText(array &$commands, float $centerX, float $y, string $text, int $size, string $font, array $color): void
    {
        $approxWidth = strlen($text) * $size * 0.52;
        $x = max(42.0, $centerX - ($approxWidth / 2));
        $this->text($commands, $x, $y, $text, $size, $font, $color);
    }

    /**
     * @param array<int, string> $commands
     * @param array<int, float> $color
     */
    private function rightText(array &$commands, float $rightX, float $y, string $text, int $size, string $font, array $color): void
    {
        $approxWidth = strlen($text) * $size * 0.52;
        $x = max(42.0, $rightX - $approxWidth);
        $this->text($commands, $x, $y, $text, $size, $font, $color);
    }

    /**
     * @param array<int, string> $commands
     * @param array<int, float> $color
     */
    private function line(array &$commands, float $x1, float $y1, float $x2, float $y2, array $color, float $width): void
    {
        $commands[] = sprintf('%.2f %.2f %.2f RG', $color[0], $color[1], $color[2]);
        $commands[] = sprintf('%.2f w', $width);
        $commands[] = sprintf('%.2f %.2f m', $x1, $y1);
        $commands[] = sprintf('%.2f %.2f l', $x2, $y2);
        $commands[] = 'S';
    }

    /**
     * @param array<int, string> $commands
     * @param array<int, float> $color
     */
    private function strokeRect(array &$commands, float $x, float $y, float $w, float $h, array $color, float $width): void
    {
        $commands[] = sprintf('%.2f %.2f %.2f RG', $color[0], $color[1], $color[2]);
        $commands[] = sprintf('%.2f w', $width);
        $commands[] = sprintf('%.2f %.2f %.2f %.2f re', $x, $y, $w, $h);
        $commands[] = 'S';
    }

    private function escapeText(string $text): string
    {
        $text = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text) ?: $text;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private function shortText(string $text, int $max): string
    {
        return strlen($text) > $max ? substr($text, 0, $max - 3).'...' : $text;
    }
}
