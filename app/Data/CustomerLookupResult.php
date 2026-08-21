<?php

namespace App\Data;

use App\Models\Customer;

final readonly class CustomerLookupResult
{
    public function __construct(
        public Customer $customer,
        public string $source,
        public ?string $provider = null,
        public ?string $status = null,
        public ?string $condition = null,
        public ?string $address = null,
        public ?string $ubigeo = null,
    ) {}
}
