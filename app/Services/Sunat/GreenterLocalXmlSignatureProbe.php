<?php

namespace App\Services\Sunat;

use App\Data\LocalXmlSignatureResult;
use DOMDocument;
use Greenter\XMLSecLibs\Sunat\SignedXml;
use RuntimeException;
use Throwable;

/**
 * Motor local de firma/verificación XMLDSig basado en el firmador real de
 * Greenter (`Greenter\XMLSecLibs\Sunat\SignedXml`, el mismo que usa
 * `Greenter\Factory\FeFactory` para los comprobantes). No emite comprobante,
 * no usa SOAP y no abre red: firma y verifica un XML diagnóstico propio sin
 * UBL, serie, correlativo ni totales.
 *
 * La verificación carga explícitamente el certificado del perfil; no confía en
 * el certificado embebido en la firma (que por sí solo siempre se
 * auto-verifica) y por eso una clave distinta falla.
 */
final class GreenterLocalXmlSignatureProbe implements LocalXmlSignatureProbe
{
    private const DIAGNOSTIC_NAMESPACE = 'urn:meowlab:facturaya:fiscal-diagnostics:1';

    public function sign(string $combinedPem): string
    {
        $signer = new SignedXml;
        $signer->setCertificate($combinedPem);

        $signed = $signer->signXml($this->diagnosticXml());

        if (! is_string($signed) || trim($signed) === '') {
            throw new RuntimeException('greenter_local_xml_signature_empty_output');
        }

        return $signed;
    }

    public function verify(string $certificatePem, string $signedXml): bool
    {
        $doc = new DOMDocument;

        if (! @$doc->loadXML($signedXml)) {
            return false;
        }

        $verifier = new SignedXml;
        $verifier->setCertificate($this->certificatePemFrom($certificatePem));

        try {
            return $verifier->verify($doc) === true;
        } catch (Throwable) {
            return false;
        }
    }

    public function signAndVerify(string $combinedPem): LocalXmlSignatureResult
    {
        $signed = $this->sign($combinedPem);

        return $this->verify($this->certificatePemFrom($combinedPem), $signed)
            ? new LocalXmlSignatureResult(LocalXmlSignatureResult::STATUS_VERIFIED)
            : new LocalXmlSignatureResult(LocalXmlSignatureResult::STATUS_FAILED);
    }

    private function diagnosticXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<DiagnosticoFiscalLocal xmlns="'.self::DIAGNOSTIC_NAMESPACE.'" version="1">'
            .'<Proposito>verificacion-firma-local-sin-emision</Proposito>'
            .'<EmitidoEn>'.gmdate('Y-m-d\TH:i:s\Z').'</EmitidoEn>'
            .'<Nonce>'.bin2hex(random_bytes(16)).'</Nonce>'
            .'</DiagnosticoFiscalLocal>';
    }

    private function certificatePemFrom(string $pem): string
    {
        if (preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $matches) === 1) {
            return $matches[0];
        }

        return $pem;
    }
}
