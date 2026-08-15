<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class IgvCalculator
{
    private const IGV_FACTOR = '0.18';

    private const TOTAL_FACTOR = '1.18';

    /**
     * @return array{
     *   quantity: string,
     *   entered_unit_price: string,
     *   unit_value: string,
     *   unit_price_with_igv: string,
     *   line_base: string,
     *   igv: string,
     *   line_total: string
     * }
     */
    public function calculateItem(string|int|float $quantity, string|int|float $price, string $taxMode): array
    {
        if (! in_array($taxMode, ['included', 'excluded'], true)) {
            throw new InvalidArgumentException('El modo de IGV debe ser included o excluded.');
        }

        $qty = BigDecimal::of((string) $quantity);
        $enteredPrice = BigDecimal::of((string) $price);

        if ($qty->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new InvalidArgumentException('La cantidad debe ser mayor que cero.');
        }

        if ($enteredPrice->isLessThan(BigDecimal::zero())) {
            throw new InvalidArgumentException('El precio no puede ser negativo.');
        }

        if ($taxMode === 'included') {
            $lineTotal = $qty->multipliedBy($enteredPrice)->toScale(2, RoundingMode::HalfUp);
            $lineBase = $lineTotal->dividedBy(self::TOTAL_FACTOR, 2, RoundingMode::HalfUp);
            $igv = $lineTotal->minus($lineBase)->toScale(2, RoundingMode::HalfUp);
            $unitValue = $enteredPrice->dividedBy(self::TOTAL_FACTOR, 6, RoundingMode::HalfUp);
            $unitPriceWithIgv = $enteredPrice->toScale(6, RoundingMode::HalfUp);
        } else {
            $lineBase = $qty->multipliedBy($enteredPrice)->toScale(2, RoundingMode::HalfUp);
            $igv = $lineBase->multipliedBy(self::IGV_FACTOR)->toScale(2, RoundingMode::HalfUp);
            $lineTotal = $lineBase->plus($igv)->toScale(2, RoundingMode::HalfUp);
            $unitValue = $enteredPrice->toScale(6, RoundingMode::HalfUp);
            $unitPriceWithIgv = $enteredPrice->multipliedBy(self::TOTAL_FACTOR)->toScale(6, RoundingMode::HalfUp);
        }

        return [
            'quantity' => $qty->toScale(3, RoundingMode::HalfUp)->__toString(),
            'entered_unit_price' => $enteredPrice->toScale(2, RoundingMode::HalfUp)->__toString(),
            'unit_value' => $unitValue->__toString(),
            'unit_price_with_igv' => $unitPriceWithIgv->__toString(),
            'line_base' => $lineBase->__toString(),
            'igv' => $igv->__toString(),
            'line_total' => $lineTotal->__toString(),
        ];
    }

    /**
     * @param  array<int, array{quantity: mixed, unit_price: mixed}>  $items
     * @return array{subtotal: string, igv: string, total: string, items: array<int, array<string, string>>}
     */
    public function calculateDocument(array $items, string $taxMode): array
    {
        $subtotal = BigDecimal::zero();
        $igv = BigDecimal::zero();
        $total = BigDecimal::zero();
        $calculatedItems = [];

        foreach ($items as $item) {
            $calculated = $this->calculateItem($item['quantity'], $item['unit_price'], $taxMode);
            $subtotal = $subtotal->plus($calculated['line_base']);
            $igv = $igv->plus($calculated['igv']);
            $total = $total->plus($calculated['line_total']);
            $calculatedItems[] = $calculated;
        }

        return [
            'subtotal' => $subtotal->toScale(2, RoundingMode::HalfUp)->__toString(),
            'igv' => $igv->toScale(2, RoundingMode::HalfUp)->__toString(),
            'total' => $total->toScale(2, RoundingMode::HalfUp)->__toString(),
            'items' => $calculatedItems,
        ];
    }
}
