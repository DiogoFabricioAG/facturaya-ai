<?php

namespace Tests\Unit;

use App\Services\IgvCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IgvCalculatorTest extends TestCase
{
    #[DataProvider('taxModes')]
    public function test_it_calculates_both_supported_igv_modes(
        string $mode,
        string $enteredPrice,
        string $expectedBase,
        string $expectedIgv,
        string $expectedTotal,
    ): void {
        $result = (new IgvCalculator)->calculateItem(1, $enteredPrice, $mode);

        $this->assertSame($expectedBase, $result['line_base']);
        $this->assertSame($expectedIgv, $result['igv']);
        $this->assertSame($expectedTotal, $result['line_total']);
    }

    public static function taxModes(): array
    {
        return [
            'precio incluido: divide entre 1.18' => ['included', '118', '100.00', '18.00', '118.00'],
            'precio sin IGV: agrega 18%' => ['excluded', '100', '100.00', '18.00', '118.00'],
        ];
    }

    public function test_it_sums_multiple_lines_without_binary_float_errors(): void
    {
        $result = (new IgvCalculator)->calculateDocument([
            ['quantity' => 1, 'unit_price' => '1200'],
            ['quantity' => 3, 'unit_price' => '250'],
        ], 'excluded');

        $this->assertSame('1950.00', $result['subtotal']);
        $this->assertSame('351.00', $result['igv']);
        $this->assertSame('2301.00', $result['total']);
    }
}
