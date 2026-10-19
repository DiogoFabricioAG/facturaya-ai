<?php

namespace Tests\Unit;

use App\Data\LocalXmlSignatureResult;
use App\Services\Sunat\GreenterLocalXmlSignatureProbe;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas focales sintéticas del motor XMLDSig real de Greenter sobre el XML
 * diagnóstico no tributario: sin SOAP, sin red, sin comprobante.
 */
final class GreenterLocalXmlSignatureProbeTest extends TestCase
{
    /**
     * @return array{0: string, 1: string} PEM combinado (clave + certificado) y solo certificado
     */
    private function keypair(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key);

        $csr = openssl_csr_new(['commonName' => 'meowlab-xml-signature-test'], $key, ['digest_alg' => 'sha256']);
        self::assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256']);
        self::assertNotFalse($certificate);

        $certificatePem = '';
        openssl_x509_export($certificate, $certificatePem);
        $keyPem = '';
        openssl_pkey_export($key, $keyPem);

        return [$keyPem.$certificatePem, $certificatePem];
    }

    public function test_real_engine_signs_and_verifies_diagnostic_xml_with_same_certificate(): void
    {
        [$combined, $certificate] = $this->keypair();
        $probe = new GreenterLocalXmlSignatureProbe;

        $signed = $probe->sign($combined);

        $doc = new DOMDocument;
        self::assertTrue($doc->loadXML($signed));
        self::assertSame('DiagnosticoFiscalLocal', $doc->documentElement->localName);
        self::assertSame(
            1,
            $doc->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')->length,
        );

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('d', 'urn:meowlab:facturaya:fiscal-diagnostics:1');
        self::assertSame(0, $xpath->query('//d:Serie')->length);
        self::assertSame(0, $xpath->query('//d:Correlativo')->length);

        self::assertTrue($probe->verify($certificate, $signed));

        $result = $probe->signAndVerify($combined);
        self::assertSame(LocalXmlSignatureResult::STATUS_VERIFIED, $result->status);
        self::assertNull($result->reason);

        self::assertStringNotContainsString('PRIVATE KEY', $signed);
    }

    public function test_tampered_signed_xml_fails_verification(): void
    {
        [$combined, $certificate] = $this->keypair();
        $probe = new GreenterLocalXmlSignatureProbe;

        $signed = $probe->sign($combined);
        $tampered = str_replace('verificacion-firma-local-sin-emision', 'alterado-manualmente', $signed);

        self::assertStringNotContainsString('verificacion-firma-local-sin-emision', $tampered);
        self::assertFalse($probe->verify($certificate, $tampered));
        self::assertTrue($probe->verify($certificate, $signed));
    }

    public function test_certificate_from_another_key_fails_verification(): void
    {
        [$combinedA, $certificateA] = $this->keypair();
        [, $certificateB] = $this->keypair();
        $probe = new GreenterLocalXmlSignatureProbe;

        $signed = $probe->sign($combinedA);

        self::assertTrue($probe->verify($certificateA, $signed));
        self::assertFalse($probe->verify($certificateB, $signed));
    }

    public function test_verification_rejects_invalid_xml(): void
    {
        [, $certificate] = $this->keypair();

        self::assertFalse((new GreenterLocalXmlSignatureProbe)->verify($certificate, 'no-es-xml'));
    }
}
