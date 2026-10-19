<?php

namespace App\Data;

use InvalidArgumentException;

/**
 * Resultado de la firma/verificación XMLDSig local con el motor real de
 * Greenter sobre un XML diagnóstico no tributario.
 *
 * `verified` significa que el mismo material firmó y verificó el XMLDSig en
 * memoria, sin red. Nunca acredita aceptación de SUNAT, CDR ni emisión: es
 * evidencia técnica local.
 */
final readonly class LocalXmlSignatureResult
{
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_FAILED = 'failed';

    private const STATUSES = [self::STATUS_VERIFIED, self::STATUS_FAILED];

    public function __construct(
        public string $status,
        public ?string $reason = null,
    ) {
        if (! in_array($this->status, self::STATUSES, true)) {
            throw new InvalidArgumentException('local_xml_signature_invalid_status');
        }

        if ($this->reason !== null && (! is_string($this->reason) || $this->reason === '')) {
            throw new InvalidArgumentException('local_xml_signature_invalid_reason');
        }
    }
}
