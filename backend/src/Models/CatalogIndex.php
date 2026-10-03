<?php
declare(strict_types=1);

namespace App\Models;

/**
 * The whole catalog (1,000 products) in memory: product rows, an inverted keyword index
 * and the embedding vectors. Built by CatalogIndexService and cached in APCu.
 */
final class CatalogIndex
{
    /**
     * @param array<string, Product> $products id => product
     * @param array<string, array<string, float>> $terms term => [product id => best field weight]
     * @param array<string, string> $vectors id => packed float32 embedding. Kept packed on purpose:
     *        as PHP float arrays the cached index is ~16 MB and takes ~70 ms to load per request;
     *        packed it is ~2 MB and loading plus unpacking takes ~13 ms.
     * @param array<string, string> $normalizedNames id => normalized name
     */
    public function __construct(
        public readonly array $products,
        public readonly array $terms,
        public readonly array $vectors,
        public readonly array $normalizedNames,
    ) {
    }

    public function hasVectors(): bool
    {
        return $this->vectors !== [];
    }
}
