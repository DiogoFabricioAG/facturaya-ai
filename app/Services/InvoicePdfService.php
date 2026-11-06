<?php

namespace App\Services;

use App\Models\Invoice;

/**
 * Builds the human-friendly representation of an issued electronic document.
 *
 * SUNAT receives the signed XML and returns the CDR. This PDF is deliberately
 * only a representation: its layout can evolve without changing the XML,
 * signature, numbering or the response received from SUNAT.
 */
final class InvoicePdfService
{
    private const PAGE_WIDTH = 595;

    private const PAGE_HEIGHT = 842;

    private const BLACK = [0.0, 0.0, 0.0];

    private const GRAY = [0.35, 0.35, 0.35];

    private const LINE = [0.65, 0.65, 0.65];

    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['company', 'draft.items']);

        $company = $invoice->company;
        $draft = $invoice->draft;
        $documentType = (string) ($invoice->document_type ?? $draft->document_type ?? '01');
        $customerDocumentType = $draft->customer_document_type !== null
            ? (string) $draft->customer_document_type
            : ($documentType === '03' ? '0' : '6');
        $anonymousCustomer = $documentType === '03' && $customerDocumentType === '0';
        $data = [
            'number' => $invoice->number,
            'document_type' => $documentType,
            'document_name' => $documentType === '03'
                ? 'BOLETA ELECTRONICA'
                : 'FACTURA ELECTRONICA',
            'series' => (string) $invoice->series,
            'status' => (string) $invoice->status,
            'company_name' => trim((string) $company->legal_name) ?: 'Empresa emisora',
            'company_ruc' => (string) $company->ruc,
            'company_address' => $this->address($company),
            'customer_name' => trim((string) $draft->customer_name) ?: ($anonymousCustomer ? 'Consumidor final' : 'Cliente'),
            'customer_ruc' => $anonymousCustomer ? 'No consignado' : (string) $draft->customer_ruc,
            'customer_document_label' => $anonymousCustomer ? 'SIN DOCUMENTO' : ($customerDocumentType === '1' ? 'DNI' : 'RUC'),
            'issue_date' => optional($draft->issue_date)->format('d/m/Y') ?: '-',
            'currency' => (string) ($draft->currency ?: 'PEN'),
            'currency_label' => ($draft->currency ?: 'PEN') === 'PEN' ? 'SOLES' : (string) ($draft->currency ?: 'PEN'),
            'payment_method' => $draft->payment_method ?? 'contado',
            'payment_lines' => InvoicePaymentTerms::summary($draft),
            'subtotal' => $this->money($draft->subtotal),
            'igv' => $this->money($draft->igv),
            'total' => $this->money($draft->total),
            'items' => $draft->items->map(fn ($item): array => [
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
            $company->address,
            $company->district,
            $company->province,
            $company->department,
        ], static fn ($value): bool => trim((string) $value) !== '')));
    }

    /** @param array<string, mixed> $data */
    private function makePdf(array $data): string
    {
        // Keep the representation compact and readable while allowing long item lists to paginate.
        $chunks = array_chunk($data['items'], 10);
        $chunks = $chunks === [] ? [[]] : $chunks;
        $pages = [];
        $credit = ($data['payment_method'] ?? 'contado') === 'credito';
        $pageCount = count($chunks) + ($credit ? 1 : 0);

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

            $this->drawFooter($commands, $index + 1, $pageCount);
            $pages[] = implode("\n", $commands);
        }
        if ($credit) {
            $commands = [];
            $this->text($commands, 42, 790, 'CONDICIONES DE PAGO - '.$data['number'], 12, '/F2', self::BLACK);
            foreach ($data['payment_lines'] as $index => $line) {
                $this->text($commands, 42, 755 - ($index * 24), $line, 10, '/F1', self::BLACK);
            }
            $this->drawFooter($commands, $pageCount, $pageCount);
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
            $this->text($commands, 42, 790, 'DETALLE DEL COMPROBANTE - CONTINUACION', 9, '/F2', self::BLACK);

            return 760;
        }

        $this->centerText($commands, self::PAGE_WIDTH / 2, 803, 'COMPROBANTE ELECTRONICO', 16, '/F2', self::BLACK);
        $this->line($commands, 42, 792, 553, 792, self::BLACK, 0.8);

        $this->text($commands, 42, 770, 'DATOS DEL COMPROBANTE:', 9, '/F2', self::BLACK);
        $this->text($commands, 50, 750, 'TIPO DE COMPROBANTE: '.$data['document_name'], 9, '/F1', self::BLACK);
        $this->text($commands, 305, 750, 'NUMERO: '.$data['number'], 9, '/F1', self::BLACK);
        $this->text($commands, 50, 733, 'FECHA DE EMISION: '.$data['issue_date'], 9, '/F1', self::BLACK);
        $this->text($commands, 305, 733, 'MONEDA: '.$data['currency_label'], 9, '/F1', self::BLACK);

        $this->text($commands, 42, 705, 'DATOS DEL EMISOR:', 9, '/F2', self::BLACK);
        $this->text($commands, 50, 686, 'RUC: '.$data['company_ruc'], 9, '/F1', self::BLACK);
        $this->text($commands, 50, 669, 'RAZON SOCIAL: '.$this->shortText($data['company_name'], 86), 9, '/F1', self::BLACK);
        $this->text($commands, 50, 652, 'DOMICILIO FISCAL: '.$this->shortText($data['company_address'] ?: 'No registrado', 82), 9, '/F1', self::BLACK);

        $this->text($commands, 42, 624, 'DATOS DEL CLIENTE:', 9, '/F2', self::BLACK);
        $this->text($commands, 50, 605, 'TIPO DE DOCUMENTO: '.$data['customer_document_label'], 9, '/F1', self::BLACK);
        $this->text($commands, 305, 605, 'NUMERO: '.$data['customer_ruc'], 9, '/F1', self::BLACK);
        $this->text($commands, 50, 588, 'NOMBRE / RAZON SOCIAL: '.$this->shortText($data['customer_name'], 74), 9, '/F1', self::BLACK);

        $this->text($commands, 42, 559, 'DETALLE:', 9, '/F2', self::BLACK);
        $this->text($commands, 50, 575, ($data['payment_lines'][0] ?? 'FORMA DE PAGO: CONTADO').(($data['payment_method'] ?? '') === 'credito' ? ' - Ver condiciones al final' : ''), 8, '/F1', self::BLACK);

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

        foreach ($items as $index => $item) {
            $descriptionLines = $this->wrapWords($item['description'], 48);
            $rowHeight = max(24, count($descriptionLines) * 12 + 12);
            $bottom = $cursor - $rowHeight + 6;

            foreach ($descriptionLines as $lineIndex => $description) {
                $this->text($commands, 112, $cursor - ($lineIndex * 12), $description, 8, '/F1', self::BLACK);
            }

            $this->rightText($commands, 88, $cursor, $item['quantity'], 8, '/F1', self::BLACK);
            $this->rightText($commands, 470, $cursor, $item['unit_price'], 8, '/F1', self::BLACK);
            $this->rightText($commands, 543, $cursor, $item['total'], 8, '/F1', self::BLACK);
            $this->line($commands, 42, $bottom, 553, $bottom, self::LINE, 0.6);
            $cursor = $bottom - 1;
        }

        $tableBottom = $cursor + 1;
        $this->line($commands, 42, $tableBottom, 42, $top, self::BLACK, 0.7);
        $this->line($commands, 553, $tableBottom, 553, $top, self::BLACK, 0.7);
        $this->line($commands, 98, $tableBottom, 98, $top, self::LINE, 0.6);
        $this->line($commands, 410, $tableBottom, 410, $top, self::LINE, 0.6);
        $this->line($commands, 480, $tableBottom, 480, $top, self::LINE, 0.6);

        return $cursor;
    }

    /** @param array<int, string> $commands @param array<string, mixed> $data */
    private function drawTotals(array &$commands, array $data, int $cursor): void
    {
        $top = $cursor - 18;
        $left = 350;
        $right = 553;
        $this->line($commands, $left, $top, $right, $top, self::BLACK, 0.8);
        $this->text($commands, $left, $top - 18, 'SUBTOTAL', 9, '/F1', self::BLACK);
        $this->rightText($commands, $right - 10, $top - 18, (string) $data['subtotal'], 9, '/F1', self::BLACK);
        $this->text($commands, $left, $top - 37, 'IGV (18%)', 9, '/F1', self::BLACK);
        $this->rightText($commands, $right - 10, $top - 37, (string) $data['igv'], 9, '/F1', self::BLACK);
        $this->line($commands, $left, $top - 48, $right, $top - 48, self::BLACK, 0.8);
        $this->text($commands, $left, $top - 70, 'TOTAL', 11, '/F2', self::BLACK);
        $this->rightText($commands, $right - 10, $top - 70, (string) $data['total'], 11, '/F2', self::BLACK);
        $this->line($commands, $left, $top - 78, $right, $top - 78, self::BLACK, 0.8);

        $status = $data['status'] === 'accepted' ? 'ACEPTADO POR SUNAT' : strtoupper((string) $data['status']);
        $this->text($commands, 42, max(74, $top - 70), 'ESTADO: '.$status, 8, '/F1', self::GRAY);
    }

    /** @param array<int, string> $commands */
    private function drawFooter(array &$commands, int $page, int $totalPages): void
    {
        $this->line($commands, 42, 56, 553, 56, self::LINE, 0.8);
        $this->text($commands, 42, 40, 'Representacion impresa del comprobante electronico', 7, '/F1', self::GRAY);
        $this->rightText($commands, 553, 40, 'Pagina '.$page.' de '.$totalPages, 7, '/F1', self::GRAY);
    }

    /** @param array<int, string> $commands */
    private function strokeRect(array &$commands, float $x, float $y, float $width, float $height, array $color, float $lineWidth): void
    {
        $commands[] = $this->color($color, 'RG');
        $commands[] = sprintf('%.2f w %.2f %.2f %.2f %.2f re S', $lineWidth, $x, $y, $width, $height);
    }

    /** @param array<int, string> $commands */
    private function line(array &$commands, float $x1, float $y1, float $x2, float $y2, array $color, float $lineWidth): void
    {
        $commands[] = $this->color($color, 'RG');
        $commands[] = sprintf('%.2f w %.2f %.2f m %.2f %.2f l S', $lineWidth, $x1, $y1, $x2, $y2);
    }

    /** @param array<int, string> $commands */
    private function text(array &$commands, float $x, float $y, string $value, int $size, string $font, array $color, bool $boldValue = false): void
    {
        $font = $boldValue ? '/F2' : $font;
        $commands[] = $this->color($color, 'rg');
        $commands[] = sprintf('BT %s %d Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET', $font, $size, $x, $y, $this->encode($value));
    }

    /** @param array<int, string> $commands */
    private function rightText(array &$commands, float $right, float $y, string $value, int $size, string $font, array $color, bool $boldValue = false): void
    {
        $width = $this->textWidth($value, $size);
        $this->text($commands, max(42, $right - $width), $y, $value, $size, $font, $color, $boldValue);
    }

    /** @param array<int, string> $commands */
    private function centerText(array &$commands, float $center, float $y, string $value, int $size, string $font, array $color): void
    {
        $this->text($commands, $center - ($this->textWidth($value, $size) / 2), $y, $value, $size, $font, $color);
    }

    private function textWidth(string $value, int $size): float
    {
        return max(1, strlen($this->encode($value))) * $size * 0.49;
    }

    private function color(array $color, string $operator): string
    {
        return sprintf('%.3f %.3f %.3f %s', $color[0], $color[1], $color[2], $operator);
    }

    /** @return array<int, string> */
    private function wrapWords(string $value, int $maxCharacters): array
    {
        $value = trim(preg_replace('/\\s+/', ' ', $value) ?: '');

        if ($value === '') {
            return [''];
        }

        return explode("\n", wordwrap($value, $maxCharacters, "\n", true));
    }

    private function shortText(string $value, int $maxCharacters): string
    {
        $value = trim($value);

        return strlen($value) <= $maxCharacters
            ? $value
            : rtrim(substr($value, 0, max(1, $maxCharacters - 3))).'...';
    }

    private function encode(string $text): string
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($encoded === false) {
            $encoded = preg_replace('/[^\x20-\x7E]/', '?', $text) ?: '';
        }

        return strtr($encoded, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }
}
