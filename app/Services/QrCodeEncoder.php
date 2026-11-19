<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Minimal QR Code 2005 encoder (ISO/IEC 18004) for the SUNAT printed representation:
 * byte mode, error correction level Q, versions 1 to 10 (up to 151 bytes). No Micro QR.
 * Self-contained so the PDF generators keep drawing everything themselves.
 */
final class QrCodeEncoder
{
    /** Per version at level Q: [EC codewords per block, blocks in group 1, data per block g1, blocks in group 2, data per block g2]. */
    private const BLOCKS_Q = [
        1 => [13, 1, 13, 0, 0],
        2 => [22, 1, 22, 0, 0],
        3 => [18, 2, 17, 0, 0],
        4 => [26, 2, 24, 0, 0],
        5 => [18, 2, 15, 2, 16],
        6 => [24, 4, 19, 0, 0],
        7 => [18, 2, 14, 4, 15],
        8 => [22, 4, 18, 2, 19],
        9 => [20, 4, 16, 4, 17],
        10 => [24, 6, 19, 2, 20],
    ];

    private const ALIGNMENT = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /** Format information bits for level Q. */
    private const EC_LEVEL_Q = 0b11;

    /** @var array<int, array<int, bool>> */
    private array $modules = [];

    /** @var array<int, array<int, bool>> */
    private array $function = [];

    private int $size = 0;

    /**
     * Encodes UTF-8 bytes and returns the module matrix: rows of booleans, true is dark.
     *
     * @return array<int, array<int, bool>>
     */
    public function encode(string $bytes): array
    {
        $length = strlen($bytes);
        $version = null;
        foreach (self::BLOCKS_Q as $candidate => [, $g1, $d1, $g2, $d2]) {
            $capacityBits = ($g1 * $d1 + $g2 * $d2) * 8;
            $needed = 4 + ($candidate < 10 ? 8 : 16) + $length * 8;
            if ($needed <= $capacityBits) {
                $version = $candidate;
                break;
            }
        }
        if ($version === null) {
            throw new InvalidArgumentException('qr_payload_too_long');
        }

        $this->size = $version * 4 + 17;
        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->function = $this->modules;
        $this->drawFunctionPatterns($version);
        $this->drawCodewords($this->interleave($version, $this->dataCodewords($version, $bytes)));

        $best = null;
        $bestPenalty = PHP_INT_MAX;
        $base = $this->modules;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->modules = $base;
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $penalty = $this->penalty();
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $this->modules;
            }
        }

        return $best;
    }

    /**
     * PDF drawing commands that paint the symbol in black with its top-left corner at
     * ($x, $top) and $moduleSize points per module. Adjacent dark modules of a row are
     * merged into one rectangle.
     *
     * @param  array<int, array<int, bool>>  $matrix
     */
    public static function pdfCommands(array $matrix, float $x, float $top, float $moduleSize): string
    {
        $rects = [];
        foreach ($matrix as $row => $cells) {
            $count = count($cells);
            for ($column = 0; $column < $count; $column++) {
                if (! $cells[$column]) {
                    continue;
                }
                $start = $column;
                while ($column + 1 < $count && $cells[$column + 1]) {
                    $column++;
                }
                $rects[] = sprintf('%.3f %.3f %.3f %.3f re', $x + $start * $moduleSize,
                    $top - ($row + 1) * $moduleSize, ($column - $start + 1) * $moduleSize, $moduleSize);
            }
        }

        return "0 0 0 rg\n".implode("\n", $rects)."\nf";
    }

    /** @return array<int, int> */
    private function dataCodewords(int $version, string $bytes): array
    {
        [, $g1, $d1, $g2, $d2] = self::BLOCKS_Q[$version];
        $capacity = ($g1 * $d1 + $g2 * $d2) * 8;
        $bits = '0100'.str_pad(decbin(strlen($bytes)), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach (unpack('C*', $bytes) ?: [] as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, $capacity - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $codewords = array_map('bindec', str_split($bits, 8));
        for ($pad = 0xEC; count($codewords) < $capacity / 8; $pad ^= 0xEC ^ 0x11) {
            $codewords[] = $pad;
        }

        return $codewords;
    }

    /** @param array<int, int> $data @return array<int, int> */
    private function interleave(int $version, array $data): array
    {
        [$ecLength, $g1, $d1, $g2, $d2] = self::BLOCKS_Q[$version];
        $divisor = $this->reedSolomonDivisor($ecLength);
        $blocks = [];
        $offset = 0;
        foreach ([[$g1, $d1], [$g2, $d2]] as [$count, $length]) {
            for ($block = 0; $block < $count; $block++) {
                $chunk = array_slice($data, $offset, $length);
                $offset += $length;
                $blocks[] = [$chunk, $this->reedSolomonRemainder($chunk, $divisor)];
            }
        }

        $result = [];
        for ($index = 0; $index < max($d1, $d2); $index++) {
            foreach ($blocks as [$chunk]) {
                if ($index < count($chunk)) {
                    $result[] = $chunk[$index];
                }
            }
        }
        for ($index = 0; $index < $ecLength; $index++) {
            foreach ($blocks as [, $ec]) {
                $result[] = $ec[$index];
            }
        }

        return $result;
    }

    private function multiply(int $x, int $y): int
    {
        $z = 0;
        for ($bit = 7; $bit >= 0; $bit--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $bit) & 1) * $x;
        }

        return $z & 0xFF;
    }

    /** @return array<int, int> */
    private function reedSolomonDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = $this->multiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = $this->multiply($root, 0x02);
        }

        return $result;
    }

    /** @param array<int, int> $data @param array<int, int> $divisor @return array<int, int> */
    private function reedSolomonRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $index => $coefficient) {
                $result[$index] ^= $this->multiply($coefficient, $factor);
            }
        }

        return $result;
    }

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->function[$y][$x] = true;
    }

    private function drawFunctionPatterns(int $version): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$this->size - 4, 3], [3, $this->size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
                        $distance = max(abs($dx), abs($dy));
                        $this->setFunction($x, $y, $distance !== 2 && $distance !== 4);
                    }
                }
            }
        }
        $positions = self::ALIGNMENT[$version];
        $last = count($positions) - 1;
        foreach ($positions as $i => $cx) {
            foreach ($positions as $j => $cy) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->setFunction($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormatBits(0);
        if ($version >= 7) {
            $remainder = $version;
            for ($i = 0; $i < 12; $i++) {
                $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
            }
            $bits = ($version << 12) | $remainder;
            for ($i = 0; $i < 18; $i++) {
                $dark = (($bits >> $i) & 1) === 1;
                $a = $this->size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->setFunction($a, $b, $dark);
                $this->setFunction($b, $a, $dark);
            }
        }
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (self::EC_LEVEL_Q << 3) | $mask;
        $remainder = $data;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }
        $bits = (($data << 10) | $remainder) ^ 0x5412;
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;

        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, $bit($i));
        }
        $this->setFunction(8, 7, $bit(6));
        $this->setFunction(8, 8, $bit(7));
        $this->setFunction(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($this->size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(8, $this->size - 15 + $i, $bit($i));
        }
        $this->setFunction(8, $this->size - 8, true);
    }

    /** @param array<int, int> $codewords */
    private function drawCodewords(array $codewords): void
    {
        $total = count($codewords) * 8;
        $i = 0;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vertical = 0; $vertical < $this->size; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vertical : $vertical;
                    if (! $this->function[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->function[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    default => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
                };
                if ($invert) {
                    $this->modules[$y][$x] = ! $this->modules[$y][$x];
                }
            }
        }
    }

    /** Penalty rules N1–N4; only used to choose the mask, any mask is a valid symbol. */
    private function penalty(): int
    {
        $penalty = 0;
        $size = $this->size;
        $lines = [];
        for ($i = 0; $i < $size; $i++) {
            $lines[] = $this->modules[$i];
            $lines[] = array_column($this->modules, $i);
        }
        $finderA = [true, false, true, true, true, false, true, false, false, false, false];
        $finderB = array_reverse($finderA);
        foreach ($lines as $line) {
            $run = 1;
            for ($i = 1; $i <= $size; $i++) {
                if ($i < $size && $line[$i] === $line[$i - 1]) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $penalty += 3 + $run - 5;
                    }
                    $run = 1;
                }
            }
            for ($i = 0; $i + 11 <= $size; $i++) {
                $window = array_slice($line, $i, 11);
                if ($window === $finderA || $window === $finderB) {
                    $penalty += 40;
                }
            }
        }
        $dark = 0;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $dark += $this->modules[$y][$x] ? 1 : 0;
                if ($x + 1 < $size && $y + 1 < $size) {
                    $color = $this->modules[$y][$x];
                    if ($color === $this->modules[$y][$x + 1] && $color === $this->modules[$y + 1][$x] && $color === $this->modules[$y + 1][$x + 1]) {
                        $penalty += 3;
                    }
                }
            }
        }
        $total = $size * $size;
        $penalty += intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) * 10 - 10;

        return $penalty;
    }
}
