<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;

/**
 * Content of the QR code required on the printed representation of facturas, boletas and
 * notas electrónicas (RS 097-2012/SUNAT, anexo N.° 6, numeral 6.4.3, as of 1.1.2020):
 *
 *   RUC | TIPO DE DOCUMENTO | SERIE | NÚMERO | MTO TOTAL IGV | MTO TOTAL DEL COMPROBANTE |
 *   FECHA DE EMISIÓN | TIPO DE DOCUMENTO ADQUIRENTE | NÚMERO DE DOCUMENTO ADQUIRENTE | VALOR RESUMEN
 *
 * Every field is read from the signed XML so it keeps "el mismo formato empleado en el
 * comprobante de pago electrónico". Returns null when the XML is not a readable UBL document.
 */
final class SunatQrPayload
{
    private const NAMESPACES = [
        'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
        'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
        'ds' => 'http://www.w3.org/2000/09/xmldsig#',
    ];

    public static function fromXml(string $xml): ?string
    {
        if ($xml === '') {
            return null;
        }
        $document = new DOMDocument;
        // Our own signed XML; still parsed without network access or entity expansion.
        if (! @$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            return null;
        }
        $xpath = new DOMXPath($document);
        foreach (self::NAMESPACES as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }
        $value = static function (string $query) use ($xpath): string {
            $nodes = $xpath->query($query);

            return $nodes !== false && $nodes->length > 0 ? trim((string) $nodes->item(0)?->textContent) : '';
        };

        $root = '/*[1]';
        $type = match ($document->documentElement?->localName) {
            'Invoice' => $value($root.'/cbc:InvoiceTypeCode'),
            'CreditNote' => '07',
            'DebitNote' => '08',
            default => '',
        };
        $id = $value($root.'/cbc:ID');
        if ($type === '' || ! preg_match('/^([A-Z0-9]{4})-(\d{1,8})$/', $id, $numbering)) {
            return null;
        }

        $supplier = $value($root.'/cac:AccountingSupplierParty/cac:Party/cac:PartyIdentification/cbc:ID');
        $customer = $root.'/cac:AccountingCustomerParty/cac:Party/cac:PartyIdentification/cbc:ID';
        // Sumatoria IGV: the subtotal of tax scheme 1000, "de ser el caso".
        $igv = $value($root.'/cac:TaxTotal/cac:TaxSubtotal[cac:TaxCategory/cac:TaxScheme/cbc:ID="1000"]/cbc:TaxAmount');
        $total = $value($root.'/cac:LegalMonetaryTotal/cbc:PayableAmount')
            ?: $value($root.'/cac:RequestedMonetaryTotal/cbc:PayableAmount');

        $fields = [
            $supplier,
            $type,
            $numbering[1],
            $numbering[2],
            $igv,
            $total,
            $value($root.'/cbc:IssueDate'),
            $value($customer.'/@schemeID'),
            $value($customer),
            $value('//ds:Signature//ds:DigestValue'),
        ];
        if ($supplier === '' || $total === '' || $fields[6] === '') {
            return null;
        }

        return implode('|', $fields);
    }
}
