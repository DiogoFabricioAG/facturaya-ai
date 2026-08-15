<?php

namespace App\Services;

use NumberFormatter;

final class AmountInWords
{
    public function currency(string|float|int $amount, string $currency): string
    {
        $numeric = round((float) $amount, 2);
        $integer = (int) floor($numeric);
        $cents = (int) round(($numeric - $integer) * 100);
        $formatter = new NumberFormatter('es_PE', NumberFormatter::SPELLOUT);
        $words = mb_strtoupper((string) $formatter->format($integer), 'UTF-8');

        $currencyName = $currency === 'USD' ? 'DÓLARES AMERICANOS' : 'SOLES';

        return sprintf('SON %s CON %02d/100 %s', $words, $cents, $currencyName);
    }
}
