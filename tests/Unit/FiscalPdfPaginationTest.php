<?php

namespace Tests\Unit;

use App\Services\CreditNotePdfService;
use App\Services\InvoicePdfService;
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
            'legend' => 'Factura Electrónica',
            'customer_label' => 'Razón social',
            'accepted' => true,
            'customer_document_number' => '20123456789',
            'currency_label' => 'SOLES (PEN)',
            'payment_method' => 'CRÉDITO',
            'outstanding' => 'S/ 1,416.00',
            'installments' => [['due_date' => '05/11/2026', 'amount' => 'S/ 1,416.00']],
            'amount_in_words' => 'SON MIL CUATROCIENTOS DIECISÉIS CON 00/100 SOLES',
        ];

        $service = new $serviceClass;
        $method = new ReflectionMethod($service, 'makePdf');
        $pdf = $method->invoke($service, $data);

        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertMatchesRegularExpression('~/Count ([2-9]|[1-9][0-9]+) >>~', $pdf);
        $totalsLabel = $serviceClass === CreditNotePdfService::class
            ? 'OP. GRAVADA:'
            : 'Subtotal';
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

    public function test_invoice_representation_keeps_the_layout_sections_and_legal_text(): void
    {
        $data = [
            'number' => 'B001-00000007', 'document_name' => 'BOLETA DE VENTA ELECTRÓNICA', 'legend' => 'Boleta de Venta Electrónica',
            'customer_label' => 'Nombre', 'accepted' => true, 'digest' => 'hL81j2c58Xhlr1Rl2Qncx/hECPg=',
            'company_name' => 'CREACIONES J&D', 'company_ruc' => '10410461248', 'company_address' => 'RIMAC DE CAMPOY, LIMA, LIMA, LIMA',
            'customer_name' => 'Consumidor final', 'customer_document_label' => 'SIN DOCUMENTO', 'customer_document_number' => '—',
            'issue_date' => '06/11/2026', 'currency_label' => 'SOLES (PEN)', 'payment_method' => 'CONTADO', 'outstanding' => null,
            'installments' => [], 'subtotal' => 'S/ 100.00', 'igv' => 'S/ 18.00', 'total' => 'S/ 118.00',
            'amount_in_words' => 'SON CIENTO DIECIOCHO CON 00/100 SOLES',
            'items' => [['description' => 'Chaleco', 'quantity' => '1.000', 'unit_price' => 'S/ 118.00', 'total' => 'S/ 118.00']],
        ];
        $pdf = (new ReflectionMethod(InvoicePdfService::class, 'makePdf'))->invoke(new InvoicePdfService, $data);
        $encoded = static fn (string $value): string => iconv('UTF-8', 'Windows-1252', $value);

        self::assertMatchesRegularExpression('~/Count 1 >>~', $pdf);
        foreach (['DATOS DEL EMISOR', 'DATOS DEL COMPROBANTE', 'DATOS DEL CLIENTE', 'DETALLE DE PRODUCTOS', 'CONDICIONES DE PAGO',
            'RESUMEN DE IMPORTES', '(CONTADO)', '(SIN DOCUMENTO)', '(S/ 118.00)', 'SON CIENTO DIECIOCHO CON 00/100 SOLES',
            'Valor resumen: hL81j2c58Xhlr1Rl2Qncx/hECPg=', 'ww3.sunat.gob.pe/ol-ti-itconsvalicpe'] as $text) {
            self::assertStringContainsString($text, $pdf);
        }
        self::assertStringContainsString($encoded('(BOLETA DE VENTA ELECTRÓNICA)'), $pdf);
        self::assertStringContainsString($encoded('Representación impresa de la Boleta de Venta Electrónica · Aceptada por SUNAT'), $pdf);
        self::assertStringContainsString('/Encoding /WinAnsiEncoding', $pdf);

        preg_match('~BT /F2 ([0-9.]+) Tf 1 0 0 1 ([0-9.]+) 790.00 Tm \('.preg_quote($encoded('BOLETA DE VENTA ELECTRÓNICA'), '~').'\)~', $pdf, $title);
        self::assertNotEmpty($title, 'The document title must be drawn inside the header box.');
        self::assertGreaterThanOrEqual(348, (float) $title[2], 'The boleta title must fit inside its box.');
    }

    public static function renderers(): iterable
    {
        yield 'invoice' => [InvoicePdfService::class];
        yield 'credit note' => [CreditNotePdfService::class];
    }
}
