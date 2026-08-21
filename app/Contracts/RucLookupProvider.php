<?php

namespace App\Contracts;

use App\Data\RucLookupData;

interface RucLookupProvider
{
    public function lookup(string $ruc): ?RucLookupData;
}
