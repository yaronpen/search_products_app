<?php
declare(strict_types=1);

namespace App\Support;

/** Binary storage and math for embedding vectors. */
final class Vector
{
    /** @param list<float> $vector float32 little-endian */
    public static function pack(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /** @return list<float> */
    public static function unpack(string $blob): array
    {
        return array_values(unpack('g*', $blob));
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function dot(array $a, array $b): float
    {
        $dot = 0.0;
        foreach ($a as $i => $v) {
            $dot += $v * $b[$i];
        }
        return $dot;
    }
}
