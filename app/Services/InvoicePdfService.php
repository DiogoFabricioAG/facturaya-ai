<?php

namespace App\Services;

use App\Models\Invoice;

/**
 * Builds a lightweight, printable PDF representation of an issued invoice.
 *
 * SUNAT receives the signed XML; this PDF is the human-friendly copy that can
 * be downloaded or shared with the customer.
 */
final class InvoicePdfService
{
    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['company', 'draft.items']);
        $company = $invoice->company;
        $draft = $invoice->draft;

        $lines = [
            ['text' => 'FACTURA ELECTRONICA', 'size' => 18, 'bold' => true],
            ['text' => $invoice->number, 'size' => 13, 'bold' => true],
            ['text' => ''],
            ['text' => (string) $company->legal_name, 'size' => 11, 'bold' => true],
            ['text' => 'RUC '.$company->ruc],
            ['text' => trim(implode(' · ', array_filter([
                $company->address,
                $company->district,
                $company->province,
                $company->department,
            ])))],
            ['text' => ''],
            ['text' => 'CLIENTE', 'size' => 8, 'bold' => true],
            ['text' => (string) $draft->customer_name, 'size' => 11, 'bold' => true],
            ['text' => 'RUC '.$draft->customer_ruc],
            ['text' => 'Fecha de emisión: '.optional($draft->issue_date)->format('d/m/Y').'   Moneda: '.($draft->currency ?: 'PEN')],
            ['text' => ''],
            ['rule' => true],
            ['text' => 'DESCRIPCION                                      CANT.       P. UNIT.          TOTAL', 'size' => 8, 'bold' => true],
            ['rule' => true],
        ];

        foreach ($draft->items as $item) {
            $description = trim((string) $item->description) ?: 'Concepto';
            $wrapped = explode("\n", wordwrap($description, 42, "\n", true));
            $first = array_shift($wrapped);
            $lines[] = ['text' => sprintf(
                '%-42s %7s %14s %16s',
                $first,
                number_format((float) $item->quantity, 3, '.', ''),
                $this->money($item->unit_price_with_igv ?: $item->entered_unit_price),
                $this->money($item->line_total),
            ), 'size' => 8];
            foreach ($wrapped as $continued) {
                $lines[] = ['text' => '  '.$continued, 'size' => 8];
            }
        }

        $lines[] = ['rule' => true];
        $lines[] = ['text' => sprintf('%-55s %16s', 'Valor de venta', $this->money($draft->subtotal)), 'size' => 9];
        $lines[] = ['text' => sprintf('%-55s %16s', 'IGV (18%%)', $this->money($draft->igv)), 'size' => 9];
        $lines[] = ['text' => sprintf('%-55s %16s', 'TOTAL', $this->money($draft->total)), 'size' => 12, 'bold' => true];
        $lines[] = ['text' => ''];
        $lines[] = ['text' => 'Estado SUNAT: '.strtoupper((string) $invoice->status).' · Codigo: '.($invoice->sunat_code ?: '—'), 'size' => 8];
        $lines[] = ['text' => (string) $invoice->sunat_message, 'size' => 8];
        $lines[] = ['text' => 'Representacion impresa del comprobante electronico.', 'size' => 8];

        return $this->makePdf($lines);
    }

    private function money(mixed $value): string
    {
        return 'S/ '.number_format((float) $value, 2, '.', ',');
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function makePdf(array $lines): string
    {
        $pages = array_chunk($lines, 42);
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
        ];
        $pageReferences = [];

        foreach ($pages as $index => $pageLines) {
            $pageObject = 5 + ($index * 2);
            $contentObject = $pageObject + 1;
            $pageReferences[] = $pageObject.' 0 R';
            $objects[$pageObject] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                $contentObject,
            );
            $stream = $this->contentStream($pageLines);
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

    /** @param array<int, array<string, mixed>> $lines */
    private function contentStream(array $lines): string
    {
        $commands = [
            'q',
            '0.19 0.33 0.85 rg',
            '50 786 8 36 re f',
            'Q',
            'BT',
        ];
        $y = 804;

        foreach ($lines as $line) {
            if (($line['rule'] ?? false) === true) {
                $commands[] = 'ET';
                $commands[] = '0.86 0.88 0.92 RG';
                $commands[] = '0.7 w';
                $commands[] = sprintf('50 %d m 545 %d l S', $y + 4, $y + 4);
                $commands[] = 'BT';
                $y -= 9;
                continue;
            }

            $text = $this->encode((string) ($line['text'] ?? ''));
            $size = (int) ($line['size'] ?? 10);
            $font = ($line['bold'] ?? false) ? '/F2' : '/F1';
            $commands[] = sprintf('%s %d Tf 1 0 0 1 58 %d Tm (%s) Tj', $font, $size, $y, $text);
            $y -= $size >= 12 ? 22 : 15;
        }

        $commands[] = 'ET';
        return implode("\n", $commands);
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
