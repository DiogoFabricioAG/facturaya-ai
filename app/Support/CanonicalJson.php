<?php

namespace App\Support;

use InvalidArgumentException;
use JsonException;

/**
 * JSON canónico para huellas de vista previa.
 *
 * Ordena claves recursivamente y conserva listas en su posición. Los valores
 * deben llegar ya normalizados (por ejemplo, decimales como cadenas), porque
 * esta clase no convierte tipos fiscales.
 */
final class CanonicalJson
{
    /**
     * @param  array<string, mixed>  $value
     */
    public static function encode(array $value): string
    {
        try {
            return json_encode(
                self::normalize($value),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('canonical_json_failed', 0, $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public static function digest(array $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                static fn (mixed $item): mixed => self::normalize($item),
                $value,
            );
        }

        ksort($value, SORT_STRING);

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[(string) $key] = self::normalize($item);
        }

        return $normalized;
    }
}
