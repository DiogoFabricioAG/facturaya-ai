<?php

namespace App\Data;

use InvalidArgumentException;

/**
 * Resultado de un matcher confiable de identidad del certificado emisor.
 *
 * `match` solo puede emitirlo un matcher inyectado explícitamente por el
 * servidor y asociado a un perfil CA/OIDs documentados; nunca una heurística
 * de subject ni el CN. `unknown`/`ambiguous` mantienen la evidencia en
 * `pending`; `conflict` expresa una contradicción demostrada por ese matcher
 * confiable, no una afirmación tributaria de SUNAT.
 */
final readonly class CertificateIdentityMatchResult
{
    public const STATUS_MATCH = 'match';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_CONFLICT = 'conflict';

    private const STATUSES = [
        self::STATUS_MATCH,
        self::STATUS_UNKNOWN,
        self::STATUS_AMBIGUOUS,
        self::STATUS_CONFLICT,
    ];

    public function __construct(
        public string $status,
        public ?string $reason = null,
    ) {
        if (! in_array($this->status, self::STATUSES, true)) {
            throw new InvalidArgumentException('certificate_identity_invalid_status');
        }

        if ($this->reason !== null && (! is_string($this->reason) || $this->reason === '')) {
            throw new InvalidArgumentException('certificate_identity_invalid_reason');
        }
    }
}
