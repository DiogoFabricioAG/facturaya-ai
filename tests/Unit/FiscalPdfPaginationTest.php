<?php

namespace Tests\Unit;

use App\Services\CreditNotePdfService;
use App\Services\FiscalInvoicePdfService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class FiscalPdfPaginationTest extends TestCase
{
    #[DataProvider('renderers')]
    public function test_long_items_are_paginated_and_totals_remain_above_the_footer(string $serviceClass): void
    {
        $items = [];
        for ($index = 0; $index < 12; $index++) {
            $marker = sprintf('END-ITEM-%02d', $index);
            $description = $index < 10
                ? str_repeat('á', 488).$marker
                : 'Servicio sintetico '.$marker;
            $items[] = [
                'description' => $description,
                'quantity' => '1.000',
                'unit_price' => 'S/ 100.00',
                'total' => 'S/ 118.00',
            ];
        }
        $data = [
            'number' => 'F001-000001',
            'document_type' => '01',
            'document_name' => 'FACTURA ELECTRONICA',
            'series' => 'F001',
            'status' => 'accepted',
            'sunat_code' => '0',
            'sunat_message' => '',
            'digest' => null,
            'origin_number' => 'F001-000010',
            'reason_code' => '01',
            'reason_description' => 'Motivo sintetico',
            'company_name' => 'Empresa sintetica',
            'company_ruc' => '20123456789',
            'company_address' => 'Direccion sintetica',
            'customer_name' => 'Cliente sintetico',
            'customer_ruc' => '20123456789',
            'customer_document_label' => 'RUC',
            'issue_date' => '05/10/2026',
            'currency' => 'PEN',
            'subtotal' => 'S/ 1,200.00',
            'igv' => 'S/ 216.00',
            'total' => 'S/ 1,416.00',
            'items' => $items,
        ];

        $service = new $serviceClass;
        $method = new ReflectionMethod($service, 'makePdf');
        $pdf = $method->invoke($service, $data);

        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertMatchesRegularExpression('~/Count ([2-9]|[1-9][0-9]+) >>~', $pdf);
        $totalsLabel = $serviceClass === CreditNotePdfService::class
            ? 'OP. GRAVADA:'
            : 'Valor de venta';
        self::assertStringContainsString($totalsLabel, $pdf);
        self::assertStringContainsString('S/ 1,416.00', $pdf);
        for ($index = 0; $index < 12; $index++) {
            self::assertStringContainsString(sprintf('END-ITEM-%02d', $index), $pdf);
        }

        preg_match_all('~(-?[0-9.]+) (-?[0-9.]+) (?:Tm|Td)~', $pdf, $matches);
        self::assertNotEmpty($matches[2]);
        foreach ($matches[2] as $y) {
            self::assertGreaterThanOrEqual(29, (float) $y, 'Text fell below the footer baseline.');
            self::assertLessThanOrEqual(842, (float) $y, 'Text exceeded the page height.');
        }
    }

    public static function renderers(): iterable
    {
        yield 'invoice' => [FiscalInvoicePdfService::class];
        yield 'credit note' => [CreditNotePdfService::class];
    }
}
