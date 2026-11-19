<?php

namespace App\Data;

/** Contribuyente encontrado y de dónde salió (`cache` o el proveedor consultado). */
final readonly class TaxpayerLookup
{
    public function __construct(
        public RucLookupData $data,
        public string $source,
    ) {}
}
