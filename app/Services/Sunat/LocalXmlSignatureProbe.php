<?php

namespace App\Services\Sunat;

use App\Data\LocalXmlSignatureResult;

/**
 * Puerto de firma/verificación XMLDSig local con el motor real del proveedor
 * fiscal (Greenter), sin comprobante fiscal, sin SOAP y sin red.
 *
 * El XML diagnóstico es propio y no tributario: sin UBL, serie, correlativo,
 * totales ni reservas. La verificación debe usar el certificado cargado desde
 * el perfil (no ciegamente el certificado embebido en la firma) y fallar ante
 * cualquier alteración del XML o material de otra clave.
 */
interface LocalXmlSignatureProbe
{
    /**
     * Firma el XML diagnóstico con el PEM combinado (clave + certificado) y
     * devuelve el XML firmado sin contactar servicios externos.
     */
    public function sign(string $combinedPem): string;

    /**
     * Verifica el XMLDSig contra el certificado público cargado del perfil.
     */
    public function verify(string $certificatePem, string $signedXml): bool;

    /**
     * Ciclo completo firma + verificación con el mismo material cargado.
     */
    public function signAndVerify(string $combinedPem): LocalXmlSignatureResult;
}
