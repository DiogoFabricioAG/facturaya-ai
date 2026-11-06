<?php

namespace App\Services;

use RuntimeException;

/**
 * A GRE transmission attempt that did not return a SUNAT ticket.
 *
 * `definitive` is true only when SUNAT (or its token service) refused the request,
 * so the guide was not registered and a corrected new guide can be prepared.
 * Otherwise the outcome is unknown and the guide must never be sent again.
 */
final class GreSendException extends RuntimeException
{
    public function __construct(
        public readonly bool $definitive,
        public readonly string $sunatCode,
        public readonly string $sunatMessage,
        public readonly string $stage,
    ) {
        parent::__construct($definitive ? 'gre_send_rejected' : 'gre_send_uncertain');
    }
}
