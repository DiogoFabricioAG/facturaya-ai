<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;

/**
 * Printed representation of an issued factura or boleta. The XML accepted by SUNAT remains the
 * document; this layout can change without touching its signature, numbering or CDR.
 */
final class InvoicePdfService
{
    private const PAGE_WIDTH = 595;

    private const PAGE_HEIGHT = 842;

    private const LEFT = 32;

    private const RIGHT = 563;

    private const WIDTH = self::RIGHT - self::LEFT;

    /** Lowest baseline for page content; the footer lives below it. */
    private const CONTENT_FLOOR = 66;

    private const INK = [0.17, 0.21, 0.27];

    private const MUTED = [0.45, 0.50, 0.57];

    private const BORDER = [0.80, 0.83, 0.87];

    private const BAR = [0.93, 0.945, 0.96];

    private const ZEBRA = [0.976, 0.98, 0.988];

    private const FRAME = [0.24, 0.28, 0.35];

    /** Right edge of N.º, CANT., DESCRIPCIÓN and P. UNIT.; IMPORTE ends at RIGHT. */
    private const COLUMN_EDGES = [56, 112, 412, 480];

    /** Helvetica advance widths (1/1000 em) for ASCII 32..126, from the standard AFM metrics. */
    private const REGULAR_WIDTHS = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556,
        278, 278, 584, 584, 584, 556, 1015,
        667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611,
        278, 278, 278, 469, 556, 333,
        556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500,
        334, 260, 334, 584,
    ];

    /** Helvetica-Bold advance widths (1/1000 em) for ASCII 32..126. */
    private const BOLD_WIDTHS = [
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556,
        333, 333, 584, 584, 584, 611, 975,
        722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611,
        333, 278, 333, 584, 556, 333,
        556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500,
        389, 280, 389, 584,
    ];

    /** Base letter whose width matches each WinAnsi byte 0xC0..0xFF (accented letters keep the base width). */
    private const ACCENTED_BASES = 'AAAAAAACEEEEIIIIDNOOOOO+OUUUUYPbaaaaaaaceeeeiiiionooooo+ouuuuypy';

    public function __construct(private readonly AmountInWords $amountInWords = new AmountInWords) {}

    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['company', 'draft.items']);
        $company = $invoice->company;
        $draft = $invoice->draft;
        $documentType = (string) ($invoice->document_type ?: $draft->document_type ?: '01');
        $boleta = $documentType === '03';
        $customerDocumentType = $draft->customer_document_type !== null
            ? (string) $draft->customer_document_type
            : ($boleta ? '0' : '6');
        $anonymous = $boleta && $customerDocumentType === '0';
        $currency = (string) ($draft->currency ?: 'PEN');
        $money = fn (mixed $value): string => $this->money($value, $currency);
        $credit = ($draft->payment_method ?? 'contado') === 'credito';

        return $this->makePdf([
            'number' => $invoice->number,
            'document_name' => $boleta ? 'BOLETA DE VENTA ELECTRÓNICA' : 'FACTURA ELECTRÓNICA',
            'legend' => $boleta ? 'Boleta de Venta Electrónica' : 'Factura Electrónica',
            'customer_label' => $boleta ? 'Nombre' : 'Razón social',
            'accepted' => $invoice->status === 'accepted',
            'digest' => $this->extractDigest($invoice),
            'company_name' => trim((string) $company->legal_name) ?: 'Empresa emisora',
            'company_ruc' => (string) $company->ruc,
            'company_address' => $this->address($company),
            'customer_name' => trim((string) $draft->customer_name) ?: ($anonymous ? 'Consumidor final' : 'Cliente'),
            'customer_document_label' => $anonymous ? 'SIN DOCUMENTO' : ($customerDocumentType === '1' ? 'DNI' : 'RUC'),
            'customer_document_number' => $anonymous ? '—' : (string) $draft->customer_ruc,
            'issue_date' => $draft->issue_date?->format('d/m/Y') ?? '—',
            'currency_label' => match ($currency) {
                'PEN' => 'SOLES (PEN)',
                'USD' => 'DÓLARES (USD)',
                default => $currency,
            },
            'payment_method' => $credit ? 'CRÉDITO' : 'CONTADO',
            'outstanding' => $credit ? $money($draft->outstanding_amount) : null,
            'installments' => $credit ? array_map(fn (array $installment): array => [
                'due_date' => $this->date((string) $installment['due_date']),
                'amount' => $money($installment['amount']),
            ], array_values($draft->installments ?? [])) : [],
            'subtotal' => $money($draft->subtotal),
            'igv' => $money($draft->igv),
            'total' => $money($draft->total),
            'amount_in_words' => $this->amountInWords->currency($draft->total, $currency),
            'items' => $draft->items->map(fn ($item): array => [
                'description' => trim((string) $item->description) ?: 'Concepto',
                'quantity' => number_format((float) $item->quantity, 3, '.', ''),
                'unit_price' => $money($item->unit_price_with_igv ?: $item->entered_unit_price),
                'total' => $money($item->line_total),
            ])->values()->all(),
        ]);
    }

    private function money(mixed $value, string $currency): string
    {
        return ($currency === 'USD' ? 'US$ ' : 'S/ ').number_format((float) $value, 2, '.', ',');
    }

    private function date(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date ? $date->format('d/m/Y') : $value;
    }

    private function address(object $company): string
    {
        return implode(', ', array_filter(array_map('trim', [
            (string) $company->address, (string) $company->district, (string) $company->province, (string) $company->department,
        ]), static fn (string $value): bool => $value !== ''));
    }

    private function extractDigest(Invoice $invoice): ?string
    {
        if (! $invoice->xml_path || ! Storage::disk('local')->exists($invoice->xml_path)) {
            return null;
        }

        return preg_match('~<(?:[A-Za-z0-9_]+:)?DigestValue>([^<]+)</~i', Storage::disk('local')->get($invoice->xml_path), $matches) === 1
            ? trim($matches[1])
            : null;
    }

    /** @param array<string, mixed> $data */
    private function makePdf(array $data): string
    {
        $pages = [];
        $commands = [];
        $y = $this->drawDocumentSections($commands, $data, $this->drawHeader($commands, $data));
        $count = count($data['items']);
        $y = $this->drawSectionBar($commands, $y, 'DETALLE DE PRODUCTOS', $count.' '.($count === 1 ? 'producto' : 'productos'));
        $y = $this->drawItemsHeader($commands, $y);

        foreach ($data['items'] as $index => $item) {
            $lines = $this->wrap($item['description'], self::COLUMN_EDGES[2] - self::COLUMN_EDGES[1] - 16, 8.5);
            $height = max(23, count($lines) * 11 + 12);
            if ($y - $height < self::CONTENT_FLOOR) {
                $pages[] = $commands;
                $commands = [];
                $y = $this->drawSectionBar($commands, $this->drawHeader($commands, $data), 'DETALLE DE PRODUCTOS (CONTINUACIÓN)', '');
                $y = $this->drawItemsHeader($commands, $y);
            }
            $y = $this->drawItemRow($commands, $y, $index, $item, $lines, $height);
        }

        $y -= 14;
        if ($y - $this->summaryHeight($data) < self::CONTENT_FLOOR) {
            $pages[] = $commands;
            $commands = [];
            $y = $this->drawHeader($commands, $data);
        }
        $this->drawSummary($commands, $y, $data);
        $pages[] = $commands;

        $streams = [];
        foreach ($pages as $index => $page) {
            $this->drawFooter($page, $data, $index + 1, count($pages));
            $streams[] = implode("\n", $page);
        }

        return $this->assemble($streams);
    }

    /** @param array<int, string> $commands @param array<string, mixed> $data */
    private function drawHeader(array &$commands, array $data): float
    {
        $this->text($commands, self::LEFT, 801, 'DATOS DEL EMISOR', 8.5, true, self::INK);
        $y = 783;
        foreach (array_slice($this->wrap($data['company_name'], 300, 11.5, true), 0, 2) as $line) {
            $this->text($commands, self::LEFT, $y, $line, 11.5, true, self::INK);
            $y -= 14;
        }
        $y -= 3;
        $this->text($commands, self::LEFT, $y, 'RUC  '.$data['company_ruc'], 9, false, self::INK);
        $y -= 16;
        foreach (array_slice($this->wrap($data['company_address'] ?: 'Domicilio fiscal no registrado', 300, 8.5), 0, 2) as $line) {
            $this->text($commands, self::LEFT, $y, $line, 8.5, false, self::MUTED);
            $y -= 11;
        }

        $boxLeft = 348;
        $center = $boxLeft + (self::RIGHT - $boxLeft) / 2;
        $this->strokeRect($commands, $boxLeft, 733, self::RIGHT - $boxLeft, 81, self::FRAME, 1.4);
        $titleSize = 12.0;
        while ($titleSize > 9 && $this->width($data['document_name'], $titleSize, true) > self::RIGHT - $boxLeft - 20) {
            $titleSize -= 0.5;
        }
        $this->centerText($commands, $center, 790, $data['document_name'], $titleSize, true, self::INK);
        $this->centerText($commands, $center, 775, 'COMPROBANTE DE PAGO', 8, false, self::MUTED);
        $this->centerText($commands, $center, 755, (string) $data['number'], 12.5, true, self::INK);

        return min(718, $y - 4);
    }

    /** @param array<int, string> $commands @param array<string, mixed> $data */
    private function drawDocumentSections(array &$commands, array $data, float $y): float
    {
        $y = $this->drawSectionBar($commands, $y, 'DATOS DEL COMPROBANTE', '');
        $y = $this->drawPairRow($commands, $y, ['Fecha de emisión', $data['issue_date']], ['Moneda', $data['currency_label']]);
        $y = $this->drawPairRow($commands, $y, ['Tipo de comprobante', $data['document_name']], ['Serie y número', (string) $data['number']]);
        $y -= 12;

        $y = $this->drawSectionBar($commands, $y, 'DATOS DEL CLIENTE', '');
        $lines = $this->wrap($data['customer_name'], self::WIDTH - 118, 8.5);
        $height = max(20, count($lines) * 11 + 9);
        $this->strokeRect($commands, self::LEFT, $y - $height, self::WIDTH, $height, self::BORDER, 0.6);
        $this->text($commands, self::LEFT + 10, $y - 13.5, $data['customer_label'], 8, true, self::INK);
        foreach ($lines as $index => $line) {
            $this->text($commands, self::LEFT + 108, $y - 13.5 - ($index * 11), $line, 8.5, false, self::INK);
        }
        $y = $this->drawPairRow($commands, $y - $height,
            ['Tipo de documento', $data['customer_document_label']], ['Número', $data['customer_document_number']]);

        return $y - 12;
    }

    /**
     * Two label/value pairs side by side.
     *
     * @param array<int, string> $commands @param array{string, string} $left @param array{string, string} $right
     */
    private function drawPairRow(array &$commands, float $top, array $left, array $right): float
    {
        $bottom = $top - 20;
        $middle = self::LEFT + self::WIDTH / 2;
        $this->strokeRect($commands, self::LEFT, $bottom, self::WIDTH, 20, self::BORDER, 0.6);
        $this->line($commands, $middle, $bottom, $middle, $top, self::BORDER, 0.6);
        foreach ([[self::LEFT, $left], [$middle, $right]] as [$x, [$label, $value]]) {
            $this->text($commands, $x + 10, $top - 13.5, $label, 8, true, self::INK);
            $this->text($commands, $x + 108, $top - 13.5, $this->fit($value, self::WIDTH / 2 - 116, 8.5), 8.5, false, self::INK);
        }

        return $bottom;
    }

    /** @param array<int, string> $commands */
    private function drawSectionBar(array &$commands, float $top, string $title, string $note): float
    {
        $this->fillRect($commands, self::LEFT, $top - 18, self::WIDTH, 18, self::BAR);
        $this->text($commands, self::LEFT + 10, $top - 12.5, $title, 8.5, true, self::INK);
        if ($note !== '') {
            $this->rightText($commands, self::RIGHT - 10, $top - 12.5, $note, 7.5, false, self::MUTED);
        }

        return $top - 18;
    }

    /** @param array<int, string> $commands */
    private function drawItemsHeader(array &$commands, float $top): float
    {
        $bottom = $top - 22;
        [$number, $quantity, $description, $price] = self::COLUMN_EDGES;
        $this->fillRect($commands, self::LEFT, $bottom, self::WIDTH, 22, self::BAR);
        $this->strokeRect($commands, self::LEFT, $bottom, self::WIDTH, 22, self::BORDER, 0.6);
        foreach (self::COLUMN_EDGES as $edge) {
            $this->line($commands, $edge, $bottom, $edge, $top, self::BORDER, 0.6);
        }
        $baseline = $top - 14;
        $this->centerText($commands, (self::LEFT + $number) / 2, $baseline, 'N.º', 7.5, true, self::INK);
        $this->rightText($commands, $quantity - 8, $baseline, 'CANT.', 7.5, true, self::INK);
        $this->text($commands, $quantity + 8, $baseline, 'DESCRIPCIÓN', 7.5, true, self::INK);
        $this->rightText($commands, $price - 8, $baseline, 'P. UNIT.', 7.5, true, self::INK);
        $this->rightText($commands, self::RIGHT - 8, $baseline, 'IMPORTE', 7.5, true, self::INK);

        return $bottom;
    }

    /**
     * @param array<int, string> $commands @param array<string, string> $item @param array<int, string> $lines
     */
    private function drawItemRow(array &$commands, float $top, int $index, array $item, array $lines, float $height): float
    {
        $bottom = $top - $height;
        [$number, $quantity, $description, $price] = self::COLUMN_EDGES;
        if ($index % 2 === 1) {
            $this->fillRect($commands, self::LEFT, $bottom, self::WIDTH, $height, self::ZEBRA);
        }
        $this->line($commands, self::LEFT, $bottom, self::RIGHT, $bottom, self::BORDER, 0.5);
        foreach ([self::LEFT, ...self::COLUMN_EDGES, self::RIGHT] as $edge) {
            $this->line($commands, $edge, $bottom, $edge, $top, self::BORDER, 0.6);
        }
        $baseline = $top - 14.5;
        $this->centerText($commands, (self::LEFT + $number) / 2, $baseline, (string) ($index + 1), 8.5, false, self::INK);
        $this->rightText($commands, $quantity - 8, $baseline, $item['quantity'], 8.5, false, self::INK);
        foreach ($lines as $lineIndex => $line) {
            $this->text($commands, $quantity + 8, $baseline - ($lineIndex * 11), $line, 8.5, false, self::INK);
        }
        $this->rightText($commands, $price - 8, $baseline, $item['unit_price'], 8.5, false, self::INK);
        $this->rightText($commands, self::RIGHT - 8, $baseline, $item['total'], 8.5, false, self::INK);

        return $bottom;
    }

    /** @param array<string, mixed> $data */
    private function paymentRows(array $data): array
    {
        $rows = [['Forma de pago', $data['payment_method']]];
        if ($data['outstanding'] !== null) {
            $rows[] = ['Saldo pendiente', $data['outstanding']];
        }
        foreach ($data['installments'] as $index => $installment) {
            $rows[] = ['Cuota '.($index + 1).' - Vencimiento', $installment['due_date'].'  ·  '.$installment['amount']];
        }

        return $rows;
    }

    /** @param array<string, mixed> $data */
    private function summaryBodyHeight(array $data): float
    {
        return max(70, count($this->paymentRows($data)) * 16 + 12);
    }

    /** @param array<string, mixed> $data */
    private function summaryHeight(array $data): float
    {
        return 18 + $this->summaryBodyHeight($data) + 16 + count($this->wordsLines($data)) * 11;
    }

    /** @param array<string, mixed> $data @return array<int, string> */
    private function wordsLines(array $data): array
    {
        return $this->wrap($data['amount_in_words'], self::WIDTH - 10, 8);
    }

    /** @param array<int, string> $commands @param array<string, mixed> $data */
    private function drawSummary(array &$commands, float $top, array $data): void
    {
        $body = $this->summaryBodyHeight($data);
        $bottom = $top - 18 - $body;
        $leftWidth = 293;
        $rightLeft = self::LEFT + $leftWidth + 9;
        $rightWidth = self::RIGHT - $rightLeft;

        foreach ([[self::LEFT, $leftWidth, 'CONDICIONES DE PAGO'], [$rightLeft, $rightWidth, 'RESUMEN DE IMPORTES']] as [$x, $width, $title]) {
            $this->fillRect($commands, $x, $top - 18, $width, 18, self::BAR);
            $this->text($commands, $x + 10, $top - 12.5, $title, 8.5, true, self::INK);
            $this->strokeRect($commands, $x, $bottom, $width, $body + 18, self::BORDER, 0.6);
        }

        $y = $top - 18 - 16;
        foreach ($this->paymentRows($data) as [$label, $value]) {
            $this->text($commands, self::LEFT + 10, $y, $label, 8, true, self::INK);
            $this->text($commands, self::LEFT + 123, $y, $this->fit($value, $leftWidth - 133, 8.5), 8.5, false, self::INK);
            $y -= 16;
        }

        $y = $top - 18 - 16;
        $right = self::RIGHT - 10;
        foreach ([['Subtotal', $data['subtotal']], ['IGV (18%)', $data['igv']]] as [$label, $value]) {
            $this->text($commands, $rightLeft + 10, $y, $label, 8.5, false, self::INK);
            $this->rightText($commands, $right, $y, $value, 8.5, true, self::INK);
            $y -= 16;
        }
        $this->line($commands, $rightLeft + 10, $y + 8, $right, $y + 8, self::BORDER, 0.8);
        $this->text($commands, $rightLeft + 10, $y - 10, 'TOTAL', 10.5, true, self::INK);
        $this->rightText($commands, $right, $y - 10, $data['total'], 13, true, self::INK);

        $y = $bottom - 16;
        foreach ($this->wordsLines($data) as $line) {
            $this->text($commands, self::LEFT, $y, $line, 8, true, self::INK);
            $y -= 11;
        }
    }

    /** @param array<int, string> $commands @param array<string, mixed> $data */
    private function drawFooter(array &$commands, array $data, int $page, int $pages): void
    {
        $this->line($commands, self::LEFT, 54, self::RIGHT, 54, self::BORDER, 0.6);
        $status = $data['accepted'] ? ' · Aceptada por SUNAT' : '';
        $this->text($commands, self::LEFT, 42, 'Representación impresa de la '.$data['legend'].$status, 7, false, self::MUTED);
        $this->rightText($commands, self::RIGHT, 42, 'Página '.$page.' de '.$pages, 7, false, self::MUTED);
        $validation = 'Consulte su validez en SUNAT: ww3.sunat.gob.pe/ol-ti-itconsvalicpe';
        $this->text($commands, self::LEFT, 31, ($data['digest'] ? 'Valor resumen: '.$data['digest'].'  ·  ' : '').$validation, 6.5, false, self::MUTED);
    }

    /** @param array<int, string> $streams */
    private function assemble(array $streams): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $references = [];
        foreach ($streams as $index => $stream) {
            $pageObject = 5 + ($index * 2);
            $references[] = $pageObject.' 0 R';
            $objects[$pageObject] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::PAGE_WIDTH, self::PAGE_HEIGHT, $pageObject + 1);
            $objects[$pageObject + 1] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $references).'] /Count '.count($streams).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
    }

    /** @param array<int, string> $commands */
    private function fillRect(array &$commands, float $x, float $y, float $width, float $height, array $color): void
    {
        $commands[] = $this->color($color, 'rg').sprintf(' %.2f %.2f %.2f %.2f re f', $x, $y, $width, $height);
    }

    /** @param array<int, string> $commands */
    private function strokeRect(array &$commands, float $x, float $y, float $width, float $height, array $color, float $lineWidth): void
    {
        $commands[] = $this->color($color, 'RG').sprintf(' %.2f w %.2f %.2f %.2f %.2f re S', $lineWidth, $x, $y, $width, $height);
    }

    /** @param array<int, string> $commands */
    private function line(array &$commands, float $x1, float $y1, float $x2, float $y2, array $color, float $lineWidth): void
    {
        $commands[] = $this->color($color, 'RG').sprintf(' %.2f w %.2f %.2f m %.2f %.2f l S', $lineWidth, $x1, $y1, $x2, $y2);
    }

    /** @param array<int, string> $commands */
    private function text(array &$commands, float $x, float $y, string $value, float $size, bool $bold, array $color): void
    {
        $escaped = strtr($this->winAnsi($value), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
        $commands[] = $this->color($color, 'rg').sprintf(' BT %s %.2f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET', $bold ? '/F2' : '/F1', $size, $x, $y, $escaped);
    }

    /** @param array<int, string> $commands */
    private function rightText(array &$commands, float $right, float $y, string $value, float $size, bool $bold, array $color): void
    {
        $this->text($commands, $right - $this->width($value, $size, $bold), $y, $value, $size, $bold, $color);
    }

    /** @param array<int, string> $commands */
    private function centerText(array &$commands, float $center, float $y, string $value, float $size, bool $bold, array $color): void
    {
        $this->text($commands, $center - $this->width($value, $size, $bold) / 2, $y, $value, $size, $bold, $color);
    }

    private function color(array $color, string $operator): string
    {
        return sprintf('%.3f %.3f %.3f %s', $color[0], $color[1], $color[2], $operator);
    }

    private function winAnsi(string $value): string
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);

        return $encoded === false ? (preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '') : $encoded;
    }

    private function width(string $value, float $size, bool $bold): float
    {
        $table = $bold ? self::BOLD_WIDTHS : self::REGULAR_WIDTHS;
        $units = 0;
        foreach (unpack('C*', $this->winAnsi($value)) ?: [] as $byte) {
            $units += match (true) {
                $byte >= 32 && $byte <= 126 => $table[$byte - 32],
                $byte >= 0xC0 => $table[ord(self::ACCENTED_BASES[$byte - 0xC0]) - 32],
                $byte === 0x97 => 1000,
                $byte === 0xB7 => 278,
                $byte === 0xBA => 365,
                $byte === 0xB0 => 400,
                default => 556,
            };
        }

        return $units * $size / 1000;
    }

    /** Shortens a single-line value to the available width. */
    private function fit(string $value, float $maxWidth, float $size, bool $bold = false): string
    {
        if ($this->width($value, $size, $bold) <= $maxWidth) {
            return $value;
        }
        $characters = mb_str_split($value);
        while ($characters !== [] && $this->width(implode('', $characters).'...', $size, $bold) > $maxWidth) {
            array_pop($characters);
        }

        return rtrim(implode('', $characters)).'...';
    }

    /** @return array<int, string> */
    private function wrap(string $value, float $maxWidth, float $size, bool $bold = false): array
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '') {
            return [''];
        }
        $lines = [];
        $line = '';
        foreach (explode(' ', $value) as $word) {
            while ($this->width($word, $size, $bold) > $maxWidth) {
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                $piece = '';
                foreach (mb_str_split($word) as $character) {
                    if ($piece !== '' && $this->width($piece.$character, $size, $bold) > $maxWidth) {
                        break;
                    }
                    $piece .= $character;
                }
                $lines[] = $piece;
                $word = mb_substr($word, mb_strlen($piece));
            }
            $candidate = $line === '' ? $word : $line.' '.$word;
            if ($line !== '' && $this->width($candidate, $size, $bold) > $maxWidth) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }
}
