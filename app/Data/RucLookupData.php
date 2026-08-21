<?php

namespace App\Data;

final readonly class RucLookupData
{
    public function __construct(
        public string $ruc,
        public string $legalName,
        public ?string $status = null,
        public ?string $condition = null,
        public ?string $address = null,
        public ?string $ubigeo = null,
        public string $provider = 'unknown',
        public ?string $asOf = null,
    ) {}
}
