<?php

namespace App\Data;

use App\Models\Customer;

final readonly class DniLookupResult
{
    public function __construct(
        public Customer $customer,
        public string $source,
        public ?DniLookupData $data = null,
    ) {}
}
