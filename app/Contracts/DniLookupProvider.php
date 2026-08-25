<?php

namespace App\Contracts;

use App\Data\DniLookupData;

interface DniLookupProvider
{
    public function lookup(string $dni): ?DniLookupData;
}
