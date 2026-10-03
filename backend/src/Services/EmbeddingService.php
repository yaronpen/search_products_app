<?php
declare(strict_types=1);

namespace App\Services;

use App\Clients\OpenAiEmbeddingsClient;
use App\Models\Product;
use App\Repositories\QueryEmbeddingRepository;
use App\Support\TextNormalizer;
use App\Support\Vector;

/**
 * Everything embedding-related above the raw HTTP call: what text gets embedded for a product,
 * and query vectors with a two-level cache (APCu, then MySQL). Without an API key the
 * service reports itself disabled and search runs keyword-only.
 */
final class EmbeddingService
{
    public function __construct(
        private readonly ?OpenAiEmbeddingsClient $client,
        private readonly QueryEmbeddingRepository $queryCache,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->client !== null;
    }

    /** @return list<float> */
    public function embedQuery(string $text): array
    {
        $normalized = TextNormalizer::normalize(trim($text));
        $hash = $this->hash($normalized);

        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch('qvec:' . $hash, $hit);
            if ($hit) {
                return $cached;
            }
        }

        $blob = $this->queryCache->find($hash);
        if ($blob !== null) {
            $vector = Vector::unpack($blob);
        } else {
            $vector = $this->requireClient()->embed([$normalized])[0];
            $this->queryCache->save($hash, $normalized, Vector::pack($vector));
        }

        if (function_exists('apcu_store')) {
            apcu_store('qvec:' . $hash, $vector, 86400);
        }
        return $vector;
    }

    /**
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function embedMany(array $texts): array
    {
        return $this->requireClient()->embed($texts);
    }

    /** The text we embed for a product: everything a shopper might describe, in a stable order. */
    public function productText(Product $product): string
    {
        return implode("\n", array_filter([
            $product->name,
            $product->brand ? "מותג: {$product->brand}" : null,
            "קטגוריה: {$product->category} > {$product->subcategory}",
            $product->description,
            $product->attributes,
        ]));
    }

    /**
     * Includes model + dimensions, so changing either in .env invalidates every stored vector:
     * the next import re-embeds all products and old cached query vectors are never reused.
     */
    public function hash(string $text): string
    {
        $client = $this->requireClient();
        return sha1($client->model . '|' . $client->dimensions . '|' . $text);
    }

    private function requireClient(): OpenAiEmbeddingsClient
    {
        return $this->client ?? throw new \RuntimeException('Embeddings are disabled (OPENAI_API_KEY not set)');
    }
}
