<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;

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

    private const NAVY = [0.10, 0.12, 0.16];

    private const BLUE = [0.22, 0.26, 0.32];

    private const TEAL = [0.62, 0.64, 0.68];

    private const MUTED = [0.38, 0.40, 0.44];

    private const LINE = [0.86, 0.89, 0.93];

    private const SURFACE = [0.96, 0.97, 0.99];

    private const PALE_GREEN = [0.95, 0.95, 0.95];

    private const PALE_AMBER = [0.95, 0.95, 0.95];

    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['company', 'draft.items']);

        $company = $invoice->company;
        $draft = $invoice->draft;
        $digest = $this->extractDigest($invoice);

        $data = [
            'number' => $invoice->number,
            'document_type' => (string) ($invoice->document_type ?: $draft->document_type ?: '01'),
            'document_name' => ($invoice->document_type ?: $draft->document_type ?: '01') === '03'
                ? 'BOLETA DE VENTA ELECTRONICA'
                : 'FACTURA ELECTRONICA',
            'series' => (string) $invoice->series,
            'status' => (string) $invoice->status,
            'sunat_code' => $invoice->sunat_code !== null ? (string) $invoice->sunat_code : null,
            'sunat_message' => trim((string) $invoice->sunat_message),
            'digest' => $digest,
            'company_name' => trim((string) $company->legal_name) ?: 'Empresa emisora',
            'company_ruc' => (string) $company->ruc,
            'company_address' => $this->address($company),
            'customer_name' => trim((string) $draft->customer_name) ?: 'Cliente',
            'customer_ruc' => (string) $draft->customer_ruc,
            'customer_document_label' => ($draft->customer_document_type ?: '6') === '1' ? 'DNI' : 'RUC',
            'issue_date' => optional($draft->issue_date)->format('d/m/Y') ?: '—',
            'currency' => (string) ($draft->currency ?: 'PEN'),
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
        return trim(implode(' · ', array_filter([
            $company->address,
            $company->district,
            $company->province,
            $company->department,
        ], static fn ($value): bool => trim((string) $value) !== '')));
    }

    private function extractDigest(Invoice $invoice): ?string
    {
        if (! $invoice->xml_path || ! Storage::disk('local')->exists($invoice->xml_path)) {
            return null;
        }

        $xml = Storage::disk('local')->get($invoice->xml_path);

        if (preg_match('~<(?:(?:[A-Za-z0-9_]+):)?DigestValue>([^<]+)</~i', $xml, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    /** @param array<string, mixed> $data */
    private function makePdf(array $data): string
    {
        // Ten rows per page leaves enough room for the totals and validation panel.
        $chunks = array_chunk($data['items'], 10);
        $chunks = $chunks === [] ? [[]] : $chunks;
        $pages = [];

        foreach ($chunks as $index => $items) {
            $commands = [];
            $lastPage = $index === count($chunks) - 1;
            $this->drawPageBackground($commands);
            $tableTop = $this->drawHeader($commands, $data, $index === 0);
            $cursor = $this->drawItemsTable($commands, $items, $tableTop);

            if ($lastPage) {
                $this->drawTotalsAndValidation($commands, $data, $cursor);
            } else {
                $this->text($commands, 42, 74, 'Continua en la siguiente pagina', 8, '/F1', self::MUTED);
            }

            $this->drawFooter($commands, $index + 1, count($chunks));
            $pages[] = implode("\n", $commands);
        }

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
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
            $objects[$contentObject] = "<< /Length ".strlen($stream)." >>\nstream\n".$stream."\nendstream";
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

    /** @param array<int, string> $commands */
    private function drawPageBackground(array &$commands): void
    {
        $this->fillRect($commands, 0, 0, self::PAGE_WIDTH, self::PAGE_HEIGHT, [1.0, 1.0, 1.0]);
        $this->fillRect($commands, 0, 830, self::PAGE_WIDTH, 12, self::BLUE);
        $this->fillRect($commands, 0, 818, self::PAGE_WIDTH, 1, self::TEAL);
    }

    /** @param array<int, string> $commands @param array<string, mixed> $data */
    private function drawHeader(array &$commands, array $data, bool $firstPage): int
    {
        // Brand mark: a restrained two-tone signature rather than a generic logo box.
        $this->fillRect($commands, 42, 781, 8, 26, self::BLUE);
        $this->fillRect($commands, 50, 789, 8, 18, self::TEAL);
        $this->text($commands, 66, 801, 'FACTURAYA', 11, '/F2', self::NAVY);
        $this->text($commands, 66, 787, 'EMISION ELECTRONICA', 7, '/F1', self::MUTED);

        $this->rightText($commands, 553, 801, $data['document_name'], 9, '/F2', self::MUTED);
        $this->rightText($commands, 553, 779, (string) $data['number'], 16, '/F2', self::NAVY);

        // Issuer panel.
        $this->strokeRect($commands, 42, 664, 511, 90, self::LINE, 0.8);
        $this->text($commands, 58, 733, 'EMISOR', 7, '/F2', self::BLUE);
        $this->text($commands, 58, 713, $this->shortText($data['company_name'], 48), 12, '/F2', self::NAVY);
        $this->text($commands, 58, 696, 'RUC '.$data['company_ruc'], 9, '/F1', self::MUTED);
        $this->text($commands, 58, 680, $this->shortText($data['company_address'] ?: 'Domicilio fiscal no registrado', 78), 8, '/F1', self::MUTED);
        $this->rightText($commands, 537, 713, 'DOCUMENTO ELECTRONICO', 7, '/F2', self::MUTED);
        $this->rightText($commands, 537, 696, 'Serie '.$data['series'], 8, '/F1', self::MUTED);

        // On later pages the customer context is compact, avoiding a large empty repeat.
        if ($firstPage) {
            $this->fillRect($commands, 42, 580, 511, 68, self::SURFACE);
            $this->fillRect($commands, 42, 580, 5, 68, self::TEAL);
            $this->text($commands, 58, 630, 'CLIENTE', 7, '/F2', self::BLUE);
            $this->text($commands, 58, 610, $this->shortText($data['customer_name'], 42), 11, '/F2', self::NAVY);
            $this->text($commands, 58, 594, $data['customer_document_label'].' '.$data['customer_ruc'], 9, '/F1', self::MUTED);
            $this->line($commands, 344, 590, 344, 638, self::LINE, 0.8);
            $this->text($commands, 367, 630, 'FECHA DE EMISION', 7, '/F2', self::MUTED);
            $this->text($commands, 367, 610, (string) $data['issue_date'], 10, '/F2', self::NAVY);
            $this->text($commands, 470, 630, 'MONEDA', 7, '/F2', self::MUTED);
            $this->text($commands, 470, 610, (string) $data['currency'], 10, '/F2', self::NAVY);
            return 555;
        }

        $this->text($commands, 42, 636, 'DETALLE DEL COMPROBANTE · CONTINUACION', 8, '/F2', self::MUTED);

        return 620;
    }

    /** @param array<int, string> $commands @param array<int, array<string, string>> $items */
    private function drawItemsTable(array &$commands, array $items, int $top): int
    {
        $this->fillRect($commands, 42, $top - 28, 511, 28, self::NAVY);
        $this->text($commands, 56, $top - 18, 'DESCRIPCION', 7, '/F2', [1.0, 1.0, 1.0]);
        $this->rightText($commands, 356, $top - 18, 'CANT.', 7, '/F2', [1.0, 1.0, 1.0]);
        $this->rightText($commands, 451, $top - 18, 'P. UNIT.', 7, '/F2', [1.0, 1.0, 1.0]);
        $this->rightText($commands, 540, $top - 18, 'TOTAL', 7, '/F2', [1.0, 1.0, 1.0]);

        $cursor = $top - 48;

        foreach ($items as $index => $item) {
            $descriptionLines = $this->wrapWords($item['description'], 42);
            $rowHeight = max(30, count($descriptionLines) * 13 + 17);
            $bottom = $cursor - $rowHeight + 8;

            if ($index % 2 === 0) {
                $this->fillRect($commands, 42, $bottom, 511, $rowHeight, self::SURFACE);
            }

            foreach ($descriptionLines as $lineIndex => $description) {
                $this->text($commands, 56, $cursor - ($lineIndex * 13), $description, 8, '/F1', self::NAVY);
            }

            $this->rightText($commands, 356, $cursor, $item['quantity'], 8, '/F1', self::NAVY);
            $this->rightText($commands, 451, $cursor, $item['unit_price'], 8, '/F1', self::NAVY);
            $this->rightText($commands, 540, $cursor, $item['total'], 8, '/F2', self::NAVY);
            $this->line($commands, 42, $bottom, 553, $bottom, self::LINE, 0.6);
            $cursor = $bottom - 1;
        }

        return $cursor;
    }

    /** @param array<int, string> $commands @param array<string, mixed> $data */
    private function drawTotalsAndValidation(array &$commands, array $data, int $cursor): void
    {
        $top = $cursor - 18;
        $cardHeight = 128;
        $bottom = max(78, $top - $cardHeight);
        $cardTop = $bottom + $cardHeight;

        // Validation panel makes the status source explicit without pretending the PDF is the CDR.
        $accepted = $data['status'] === 'accepted' && (string) $data['sunat_code'] === '0';
        $this->fillRect($commands, 42, $bottom, 258, $cardHeight, $accepted ? self::PALE_GREEN : self::PALE_AMBER);
        $this->fillRect($commands, 42, $bottom, 5, $cardHeight, $accepted ? self::TEAL : self::BLUE);
        $this->text($commands, 58, $cardTop - 25, 'ESTADO DEL DOCUMENTO', 7, '/F2', self::BLUE);
        $this->text($commands, 58, $cardTop - 47, $accepted ? 'Constancia de recepcion' : 'Pendiente de validacion', 11, '/F2', self::NAVY);
        $code = $data['sunat_code'] !== null ? (string) $data['sunat_code'] : '—';
        $this->text($commands, 58, $cardTop - 64, 'Codigo CDR: '.$code, 8, '/F1', self::MUTED);
        $message = $data['sunat_message'] && ! $accepted
            ? $data['sunat_message']
            : ($accepted ? 'Constancia de recepcion registrada.' : 'Consulta el XML y la respuesta de SUNAT para completar la revision.');
        $messageLines = $this->wrapWords($message, 39);
        foreach (array_slice($messageLines, 0, 2) as $index => $line) {
            $this->text($commands, 58, $cardTop - 80 - ($index * 12), $line, 7, '/F1', self::MUTED);
        }
        $this->text($commands, 58, $bottom + 20, 'Consulta oficial SUNAT', 7, '/F2', self::BLUE);
        $this->text($commands, 58, $bottom + 8, 'ww3.sunat.gob.pe/ol-ti-itconsvalicpe', 6, '/F1', self::MUTED);

        $this->fillRect($commands, 319, $bottom, 234, $cardHeight, self::SURFACE);
        $this->text($commands, 337, $cardTop - 25, 'RESUMEN DE LA OPERACION', 7, '/F2', self::BLUE);
        $this->text($commands, 337, $cardTop - 48, 'Valor de venta', 8, '/F1', self::MUTED);
        $this->rightText($commands, 535, $cardTop - 48, (string) $data['subtotal'], 9, '/F2', self::NAVY, true);
        $this->text($commands, 337, $cardTop - 67, 'IGV (18%)', 8, '/F1', self::MUTED);
        $this->rightText($commands, 535, $cardTop - 67, (string) $data['igv'], 9, '/F2', self::NAVY, true);
        $this->line($commands, 337, $cardTop - 79, 535, $cardTop - 79, self::LINE, 0.8);
        $this->text($commands, 337, $cardTop - 104, 'TOTAL', 9, '/F2', self::NAVY);
        $this->rightText($commands, 535, $cardTop - 104, (string) $data['total'], 15, '/F2', self::BLUE, true);

        if ($data['digest']) {
            $this->text($commands, 337, $bottom + 19, 'Valor resumen: '.$this->shortText($data['digest'], 60), 6, '/F1', self::MUTED);
        }
    }

    /** @param array<int, string> $commands */
    private function drawFooter(array &$commands, int $page, int $totalPages): void
    {
        $this->line($commands, 42, 56, 553, 56, self::LINE, 0.8);
        $this->text($commands, 42, 42, 'FacturaYa AI · Representacion impresa del comprobante electronico', 7, '/F1', self::MUTED);
        $this->rightText($commands, 553, 42, 'Pagina '.$page.' de '.$totalPages, 7, '/F1', self::MUTED);
        $this->text($commands, 42, 29, 'La validez tributaria se acredita con el XML firmado y el CDR de SUNAT.', 6, '/F1', self::MUTED);
    }

    /** @param array<int, string> $commands */
    private function fillRect(array &$commands, float $x, float $y, float $width, float $height, array $color): void
    {
        $commands[] = $this->color($color, 'rg');
        $commands[] = sprintf('%.2f %.2f %.2f %.2f re f', $x, $y, $width, $height);
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
