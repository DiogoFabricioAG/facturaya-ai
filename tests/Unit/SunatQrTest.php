<?php

namespace Tests\Unit;

use App\Services\CreditNotePdfService;
use App\Services\InvoicePdfService;
use App\Services\QrCodeEncoder;
use App\Services\SunatQrPayload;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SunatQrTest extends TestCase
{
    private static function ubl(string $root, string $id, string $type, string $customerScheme, string $customerId, string $igv, string $total): string
    {
        $typeCode = $root === 'Invoice' ? '<cbc:InvoiceTypeCode listID="0101">'.$type.'</cbc:InvoiceTypeCode>' : '';
        $totals = $root === 'Invoice' ? 'LegalMonetaryTotal' : 'LegalMonetaryTotal';

        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<{$root} xmlns="urn:oasis:names:specification:ubl:schema:xsd:{$root}-2"
  xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
  xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"
  xmlns:ds="http://www.w3.org/2000/09/xmldsig#"
  xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">
  <ext:UBLExtensions><ext:UBLExtension><ext:ExtensionContent>
    <ds:Signature Id="GreenterSign"><ds:SignedInfo><ds:Reference URI=""><ds:DigestValue>1OH+nCfkiFHjp1iaz9In1haQMWU=</ds:DigestValue></ds:Reference></ds:SignedInfo></ds:Signature>
  </ext:ExtensionContent></ext:UBLExtension></ext:UBLExtensions>
  <cbc:UBLVersionID>2.1</cbc:UBLVersionID>
  <cbc:ID>{$id}</cbc:ID>
  <cbc:IssueDate>2026-11-06</cbc:IssueDate>
  {$typeCode}
  <cac:AccountingSupplierParty><cac:Party><cac:PartyIdentification><cbc:ID schemeID="6">10410461248</cbc:ID></cac:PartyIdentification></cac:Party></cac:AccountingSupplierParty>
  <cac:AccountingCustomerParty><cac:Party><cac:PartyIdentification><cbc:ID schemeID="{$customerScheme}">{$customerId}</cbc:ID></cac:PartyIdentification></cac:Party></cac:AccountingCustomerParty>
  <cac:TaxTotal>
    <cbc:TaxAmount currencyID="PEN">{$igv}</cbc:TaxAmount>
    <cac:TaxSubtotal><cbc:TaxableAmount currencyID="PEN">1016.94</cbc:TaxableAmount><cbc:TaxAmount currencyID="PEN">{$igv}</cbc:TaxAmount>
      <cac:TaxCategory><cac:TaxScheme><cbc:ID>1000</cbc:ID><cbc:Name>IGV</cbc:Name></cac:TaxScheme></cac:TaxCategory></cac:TaxSubtotal>
  </cac:TaxTotal>
  <cac:{$totals}><cbc:PayableAmount currencyID="PEN">{$total}</cbc:PayableAmount></cac:{$totals}>
</{$root}>
XML;
    }

    public function test_payload_follows_the_anexo_6_order_with_the_xml_formats(): void
    {
        $factura = self::ubl('Invoice', 'F001-26', '01', '6', '20207341241', '183.06', '1200.00');
        self::assertSame(
            '10410461248|01|F001|26|183.06|1200.00|2026-11-06|6|20207341241|1OH+nCfkiFHjp1iaz9In1haQMWU=',
            SunatQrPayload::fromXml($factura),
        );

        $boleta = self::ubl('Invoice', 'B001-7', '03', '0', '-', '18.00', '118.00');
        self::assertSame('10410461248|03|B001|7|18.00|118.00|2026-11-06|0|-|1OH+nCfkiFHjp1iaz9In1haQMWU=', SunatQrPayload::fromXml($boleta));

        $note = self::ubl('CreditNote', 'FC01-3', '', '6', '20207341241', '36.00', '236.00');
        self::assertSame('10410461248|07|FC01|3|36.00|236.00|2026-11-06|6|20207341241|1OH+nCfkiFHjp1iaz9In1haQMWU=', SunatQrPayload::fromXml($note));
    }

    public function test_payload_is_null_without_a_readable_document(): void
    {
        self::assertNull(SunatQrPayload::fromXml(''));
        self::assertNull(SunatQrPayload::fromXml('<html>no</html>'));
        self::assertNull(SunatQrPayload::fromXml('not xml'));
    }

    public function test_encoder_draws_a_valid_symbol_structure(): void
    {
        $payload = '10410461248|01|F001|26|183.06|1200.00|2026-11-06|6|20207341241|1OH+nCfkiFHjp1iaz9In1haQMWU=';
        $matrix = (new QrCodeEncoder)->encode($payload);
        // 94 bytes at level Q need version 8: 49 × 49 modules.
        self::assertCount(49, $matrix);
        foreach ($matrix as $row) {
            self::assertCount(49, $row);
        }
        // Finder patterns: dark 7×7 border, light ring, dark 3×3 core, in three corners.
        foreach ([[0, 0], [42, 0], [0, 42]] as [$x, $y]) {
            for ($i = 0; $i < 7; $i++) {
                self::assertTrue($matrix[$y][$x + $i]);
                self::assertTrue($matrix[$y + 6][$x + $i]);
                self::assertTrue($matrix[$y + $i][$x]);
            }
            self::assertFalse($matrix[$y + 1][$x + 1]);
            self::assertTrue($matrix[$y + 3][$x + 3]);
        }
        // Timing pattern and the always-dark module.
        for ($i = 8; $i < 41; $i++) {
            self::assertSame($i % 2 === 0, $matrix[6][$i]);
        }
        self::assertTrue($matrix[49 - 8][8]);
        // Deterministic: the same payload always draws the same symbol.
        self::assertSame($matrix, (new QrCodeEncoder)->encode($payload));
        self::assertCount(21, (new QrCodeEncoder)->encode('hola'));
    }

    public function test_encoder_refuses_payloads_beyond_version_10(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new QrCodeEncoder)->encode(str_repeat('x', 152));
    }

    public function test_invoice_pdf_draws_the_qr_at_the_bottom_without_crossing_the_content(): void
    {
        $items = [];
        for ($index = 0; $index < 30; $index++) {
            $items[] = ['description' => 'Producto '.$index, 'quantity' => '1.000', 'unit_price' => 'S/ 10.00', 'total' => 'S/ 10.00'];
        }
        $data = [
            'number' => 'F001-00000026', 'document_name' => 'FACTURA ELECTRÓNICA', 'legend' => 'Factura Electrónica',
            'customer_label' => 'Razón social', 'accepted' => true, 'digest' => '1OH+nCfkiFHjp1iaz9In1haQMWU=',
            'qr' => '10410461248|01|F001|26|183.06|1200.00|2026-11-06|6|20207341241|1OH+nCfkiFHjp1iaz9In1haQMWU=',
            'company_name' => 'CREACIONES J&D', 'company_ruc' => '10410461248', 'company_address' => 'RIMAC, LIMA',
            'customer_name' => 'MUNICIPALIDAD DISTRITAL DE ARMA', 'customer_document_label' => 'RUC', 'customer_document_number' => '20207341241',
            'issue_date' => '06/11/2026', 'currency_label' => 'SOLES (PEN)', 'payment_method' => 'CONTADO', 'outstanding' => null,
            'installments' => [], 'subtotal' => 'S/ 254.24', 'igv' => 'S/ 45.76', 'total' => 'S/ 300.00',
            'amount_in_words' => 'SON TRESCIENTOS CON 00/100 SOLES', 'items' => $items,
        ];
        $pdf = (new ReflectionMethod(InvoicePdfService::class, 'makePdf'))->invoke(new InvoicePdfService, $data);

        self::assertMatchesRegularExpression('~/Count 2 >>~', $pdf);
        self::assertSame(1, substr_count($pdf, "0 0 0 rg\n"), 'The QR is drawn once, on the last page.');
        self::assertStringContainsString('Valor resumen: 1OH+nCfkiFHjp1iaz9In1haQMWU=', $pdf);

        // Every text line stays above the footer rule (106) or inside the footer band, never on the QR.
        preg_match_all('~1 0 0 1 ([0-9.]+) ([0-9.]+) Tm \(([^)]*)\)~', $pdf, $texts, PREG_SET_ORDER);
        foreach ($texts as [, $x, $y, $text]) {
            if ((float) $y < 106) {
                self::assertLessThan(563 - 66, (float) $x, 'Footer text must stay left of the QR: '.$text);
            } else {
                self::assertGreaterThanOrEqual(122 - 12, (float) $y, 'Content crossed into the footer: '.$text);
            }
        }
        // The QR spans 66 pt at the bottom right, within the page margins.
        preg_match_all('~([0-9.]+) ([0-9.]+) ([0-9.]+) ([0-9.]+) re\n~', substr($pdf, strpos($pdf, "0 0 0 rg\n")), $rects, PREG_SET_ORDER);
        $xs = array_map(fn ($r) => (float) $r[1], $rects);
        $ys = array_map(fn ($r) => (float) $r[2], $rects);
        self::assertGreaterThanOrEqual(563 - 66 - 0.01, min($xs));
        self::assertGreaterThanOrEqual(32 - 0.01, min($ys));
    }

    public function test_credit_note_pdf_draws_the_qr_on_its_last_page(): void
    {
        $data = [
            'number' => 'FC01-00000003', 'qr' => '10410461248|07|FC01|3|36.00|236.00|2026-11-06|6|20207341241|abc=',
            'document_type' => '07', 'document_name' => 'NOTA DE CREDITO ELECTRONICA', 'series' => 'FC01', 'status' => 'accepted',
            'origin_number' => 'F001-00000021', 'reason_code' => '01', 'reason_description' => 'Anulación de la operación',
            'company_name' => 'CREACIONES J&D', 'company_ruc' => '10410461248', 'company_address' => 'RIMAC, LIMA',
            'customer_name' => 'Comercial Lima', 'customer_ruc' => '20207341241', 'customer_document_label' => 'RUC',
            'issue_date' => '06/11/2026', 'currency' => 'PEN', 'currency_label' => 'SOLES',
            'subtotal' => 'S/ 200.00', 'igv' => 'S/ 36.00', 'total' => 'S/ 236.00',
            'items' => [['description' => 'Banner', 'quantity' => '1.000', 'unit_price' => 'S/ 236.00', 'total' => 'S/ 236.00']],
        ];
        $pdf = (new ReflectionMethod(CreditNotePdfService::class, 'makePdf'))->invoke(new CreditNotePdfService, $data);
        self::assertSame(1, substr_count($pdf, "0 0 0 rg\n"));
        self::assertStringContainsString('OP. GRAVADA:', $pdf);
    }
}
