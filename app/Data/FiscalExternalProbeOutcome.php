<?php

namespace App\Data;

use InvalidArgumentException;

/**
 * Resultado sanitizado de una comprobación externa no emisora.
 *
 * `transportTrusted` solo puede ser verdadero cuando el cliente SOAP inyectado
 * verifica el certificado del servidor SUNAT. Mientras la verificación TLS esté
 * deshabilitada (Greenter y el cliente de diagnóstico actuales la deshabilitan),
 * ningún resultado externo puede acreditar autenticación real.
 */
final readonly class FiscalExternalProbeOutcome
{
    public const OUTCOME_NOT_ATTEMPTED = 'not_attempted';

    public const OUTCOME_ENVIRONMENT_UNSUPPORTED = 'environment_unsupported';

    public const OUTCOME_AUTHENTICATION_ACCEPTED = 'authentication_accepted';

    public const OUTCOME_AUTHENTICATION_REJECTED = 'authentication_rejected';

    public const OUTCOME_RESPONSE_UNCLASSIFIED = 'response_unclassified';

    public const OUTCOME_TRANSPORT_ERROR = 'transport_error';

    public const MAX_SUNAT_MESSAGE_LENGTH = 300;

    private const OUTCOMES = [
        self::OUTCOME_NOT_ATTEMPTED,
        self::OUTCOME_ENVIRONMENT_UNSUPPORTED,
        self::OUTCOME_AUTHENTICATION_ACCEPTED,
        self::OUTCOME_AUTHENTICATION_REJECTED,
        self::OUTCOME_RESPONSE_UNCLASSIFIED,
        self::OUTCOME_TRANSPORT_ERROR,
    ];

    public function __construct(
        public string $outcome,
        public bool $transportTrusted = false,
        public ?string $endpointHost = null,
        public ?string $sunatCode = null,
        public ?string $sunatMessage = null,
        public ?string $occurredAt = null,
    ) {
        if (! in_array($this->outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('fiscal_diagnostics_invalid_probe_outcome');
        }

        if ($this->endpointHost !== null && preg_match('/^[A-Za-z0-9.-]{1,253}$/', $this->endpointHost) !== 1) {
            throw new InvalidArgumentException('fiscal_diagnostics_invalid_endpoint_host');
        }

        if ($this->sunatCode !== null && preg_match('/^[A-Za-z0-9._-]{1,32}$/', $this->sunatCode) !== 1) {
            throw new InvalidArgumentException('fiscal_diagnostics_invalid_sunat_code');
        }

        if ($this->sunatMessage !== null && mb_strlen($this->sunatMessage) > self::MAX_SUNAT_MESSAGE_LENGTH) {
            throw new InvalidArgumentException('fiscal_diagnostics_sunat_message_too_long');
        }
    }

    public static function notAttempted(): self
    {
        return new self(self::OUTCOME_NOT_ATTEMPTED);
    }
}
