<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\CatalogIndex;
use App\Repositories\MetaRepository;
use App\Repositories\ProductRepository;
use App\Support\TextNormalizer;

/**
 * Builds the in-memory search index from the products table, once per catalog version,
 * and caches it in APCu so a search request runs no SQL scans.
 */
final class CatalogIndexService
{
    public const VERSION_KEY = 'catalog_version';

    /**
     * Relative importance of a keyword hit in each field. The subcategory names the product type
     * as reliably as the name does: a "רובוט שואב" is a vacuum because it sits in "שואבי אבק ורובוטים".
     */
    public const FIELD_WEIGHTS = [
        'name' => 3.0,
        'subcategory' => 3.0,
        'brand' => 2.5,
        'category' => 1.2,
        'description' => 1.0,
        'attributes' => 0.8,
    ];
    public const MAX_FIELD_WEIGHT = 3.0;
    /** A hit on a stem variant (e.g. "כלבים" -> "כלב") counts a bit less than an exact word. */
    private const VARIANT_QUALITY = 0.85;

    private ?CatalogIndex $index = null;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly MetaRepository $meta,
    ) {
    }

    public function get(): CatalogIndex
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $cacheKey = 'catalog:' . ($this->meta->get(self::VERSION_KEY) ?? '0');
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey, $hit);
            if ($hit) {
                return $this->index = $cached;
            }
        }

        $this->index = $this->build();
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $this->index, 3600);
        }
        return $this->index;
    }

    /** Called after an import so every server rebuilds its cached index. */
    public function bumpVersion(): void
    {
        $this->meta->set(self::VERSION_KEY, (string) time());
        $this->index = null;
    }

    private function build(): CatalogIndex
    {
        $products = [];
        $terms = [];
        $names = [];

        $vectors = $this->products->embeddings();

        foreach ($this->products->all() as $product) {
            $id = $product->id;
            $products[$id] = $product;
            $names[$id] = TextNormalizer::normalize($product->name);

            $fields = [
                'name' => $product->name,
                'brand' => (string) $product->brand,
                'subcategory' => $product->subcategory,
                'category' => $product->category,
                'description' => $product->description,
                'attributes' => $product->attributes,
            ];
            foreach (self::FIELD_WEIGHTS as $field => $weight) {
                foreach (TextNormalizer::tokenize($fields[$field]) as $token) {
                    foreach (TextNormalizer::variants($token) as $i => $variant) {
                        $w = $i === 0 ? $weight : $weight * self::VARIANT_QUALITY;
                        if (($terms[$variant][$id] ?? 0) < $w) {
                            $terms[$variant][$id] = $w;
                        }
                    }
                }
            }
        }

        return new CatalogIndex($products, $terms, $vectors, $names);
    }
}
