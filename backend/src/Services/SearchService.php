<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\CatalogIndex;
use App\Models\Product;
use App\Support\QueryAliases;
use App\Support\TextNormalizer;
use App\Support\Vector;

/**
 * Hybrid search: keyword relevance (exact names, brands, models) blended with embedding
 * similarity (intent like "a gift for someone who loves cooking", Hebrew<->English).
 */
final class SearchService
{
    // Keywords get up to this share of the final score, and only as much as they actually
    // matched: exact-name queries ("ps5", "מחבת") match fully and lean on keywords, while
    // descriptive queries match few words and lean on meaning (see keywordWeight()).
    private const MAX_LEXICAL_WEIGHT = 0.4;
    private const LEXICAL_CONFIDENT_MATCH = 0.8;
    // Raw cosine similarity is mapped from [FLOOR, CEIL] to [0, 1]. Measured on text-embedding-3-large
    // over the golden queries: unrelated products sit around 0.13-0.31 (median), the best matches
    // at 0.40-0.67. The ceiling stays above the highest observed value so strong matches don't tie at 1.
    private const SEM_FLOOR = 0.25;
    private const SEM_CEIL = 0.75;
    // Every result must reach this absolute score (filters gibberish like "qwxzkj", best cosine ~0.29)
    private const MIN_SCORE = 0.12;
    // ...and this fraction of the best result's score. Broad for descriptive queries, strict for
    // precise ones: "iphone" shows the Apple phones, not every smartphone (see cutoffs()).
    private const BROAD_CUTOFF = 0.55;
    private const PRECISE_CUTOFF = 0.85;
    // For precise queries, a product matching the query words this fully (relative to the best
    // match) is kept even if its semantic score is noisy, e.g. every pan for "מחבת".
    private const FULL_KEYWORD_MATCH = 0.95;
    // Confidence from which a query counts as precise (enables the full-keyword-match rule)
    private const PRECISE_CONFIDENCE = 0.9;
    // "...ומוצרים דומים" adds products sharing a subcategory with this many of the best matches
    private const SIMILAR_FROM_TOP = 3;
    private const PREFIX_QUALITY = 0.6;
    private const ALIAS_QUALITY = 0.9;
    private const QUERY_VARIANT_QUALITY = 0.85;
    private const NUMBER_WEIGHT = 0.3;
    private const PHRASE_BONUS = 0.2;

    /** Index for the request being served (loaded once per search call). */
    private CatalogIndex $index;

    public function __construct(
        private readonly CatalogIndexService $catalogIndex,
        private readonly EmbeddingService $embeddings,
    ) {
    }

    /**
     * @return array{results: list<Product>, scores: array<string, array{score: float, lexical: float, semantic: ?float}>, mode: string, warning: ?string}
     */
    public function search(string $query, int $limit = 24): array
    {
        $this->index = $this->catalogIndex->get();
        $rawTokens = TextNormalizer::tokenize($query);
        // "אייפון ומוצרים דומים": search for "אייפון", then add products of the same type
        $wantsSimilar = TextNormalizer::asksForSimilar($rawTokens);
        $allTokens = array_values(array_filter(
            $rawTokens,
            fn(string $t) => !TextNormalizer::isMeta($t) && !TextNormalizer::isConnector($t),
        )) ?: $rawTokens;
        $tokens = TextNormalizer::tokenize($query, dropStopwords: true) ?: $allTokens;
        // Share of words that name things: 1.0 for "iphone 16", 0.25 for "מתנה למישהו שאוהב לבשל"
        $contentShare = count($tokens) / count($allTokens ?: [1]);
        $groups = QueryAliases::expand(array_values(array_unique($tokens)));

        $lexical = $this->lexicalScores($groups, TextNormalizer::normalize(trim($query)));

        $semantic = null;
        $warning = null;
        if ($this->embeddings->isEnabled() && $this->index->hasVectors()) {
            try {
                $aliasTerms = [];
                foreach ($groups as $phrases) {
                    foreach (array_slice($phrases, 1) as $phrase) {
                        $aliasTerms[] = implode(' ', $phrase);
                    }
                }
                // Request words ("ומוצרים דומים") would only blur the embedding toward "products in general"
                $baseText = count($allTokens) < count($rawTokens) ? implode(' ', $allTokens) : $query;
                $embedText = $aliasTerms ? $baseText . ' (' . implode(' ', $aliasTerms) . ')' : $baseText;
                $semantic = $this->semanticScores($this->embeddings->embedQuery($embedText));
            } catch (\Throwable $e) {
                // Degrade gracefully to keyword search rather than failing the request
                error_log('Semantic search unavailable: ' . $e->getMessage());
                $warning = 'semantic_unavailable';
            }
        }

        $confidence = $this->keywordConfidence($lexical, $contentShare);
        $lexicalWeight = self::MAX_LEXICAL_WEIGHT * $confidence;
        $scores = [];
        foreach ($this->index->products as $id => $_) {
            $lex = $lexical[$id] ?? 0.0;
            $scores[$id] = $semantic === null
                ? $lex
                : (1 - $lexicalWeight) * $semantic[$id] + $lexicalWeight * $lex;
        }
        arsort($scores);

        $top = reset($scores) ?: 0.0;
        $threshold = max(self::MIN_SCORE, $top * (self::BROAD_CUTOFF + (self::PRECISE_CUTOFF - self::BROAD_CUTOFF) * $confidence));
        $bestLexical = $lexical ? max($lexical) : 0.0;
        $isPrecise = $confidence >= self::PRECISE_CONFIDENCE;

        $results = [];
        $resultScores = [];
        foreach ($scores as $id => $score) {
            if (count($results) >= $limit) {
                break;
            }
            $fullKeywordMatch = $isPrecise && ($lexical[$id] ?? 0.0) >= $bestLexical * self::FULL_KEYWORD_MATCH;
            if ($score < self::MIN_SCORE || ($score < $threshold && !$fullKeywordMatch)) {
                continue;
            }
            $results[] = $this->index->products[$id];
            $resultScores[$id] = [
                'score' => round($score, 3),
                'lexical' => round($lexical[$id] ?? 0.0, 3),
                'semantic' => $semantic === null ? null : round($semantic[$id], 3),
            ];
        }

        if ($wantsSimilar && $results) {
            $this->appendSameType($results, $resultScores, $scores, $limit);
        }

        return [
            'results' => $results,
            'scores' => $resultScores,
            'mode' => $semantic === null ? 'keyword' : 'hybrid',
            'warning' => $warning,
        ];
    }

    /**
     * Adds products of the same type (subcategory) as the best matches, after them and in ranking
     * order: "אייפון ומוצרים דומים" -> the Apple phones, then the other smartphones.
     * Same subcategory rather than "semantically close", which would pull in chargers and screen
     * protectors for a phone.
     *
     * @param list<Product> $results
     * @param array<string, array> $resultScores
     * @param array<string, float> $scores all products, best first
     */
    private function appendSameType(array &$results, array &$resultScores, array $scores, int $limit): void
    {
        $types = array_unique(array_map(fn(Product $p) => $p->subcategory, array_slice($results, 0, self::SIMILAR_FROM_TOP)));
        foreach ($scores as $id => $score) {
            if (count($results) >= $limit) {
                break;
            }
            $product = $this->index->products[$id];
            if (!isset($resultScores[$id]) && in_array($product->subcategory, $types, true)) {
                $results[] = $product;
                $resultScores[$id] = ['score' => round($score, 3), 'lexical' => null, 'semantic' => null, 'similar' => true];
            }
        }
    }

    /**
     * 0..1: how precisely the query names something in the catalog, from how well the best
     * product matched its keywords. It drives both the keyword weight and the cutoff.
     * "ps5" or "iphone" -> 1 (precise: lean on keywords, show only close matches).
     * "משהו שיעזור לנקות שערות של כלב מהספה" matches at most "כלב" -> ~0.2 (descriptive: meaning
     * decides, and a broader list is the right answer).
     * A query phrased as a sentence is descriptive even if its one content word matches well:
     * "מתנה למישהו שאוהב לבשל" fully matches a multi-cooker on "לבשל", but asks for ideas, not
     * for that product. So confidence is scaled by the share of content words.
     *
     * @param array<string, float> $lexical
     */
    private function keywordConfidence(array $lexical, float $contentShare): float
    {
        $best = $lexical ? max($lexical) : 0.0;
        return min(1.0, $best / self::LEXICAL_CONFIDENT_MATCH) * $contentShare;
    }

    /**
     * Fraction of the query's (idf-weighted) terms each product matches, 0..1.
     *
     * @param array<string, list<list<string>>> $groups query token => alternative phrases
     * @return array<string, float>
     */
    private function lexicalScores(array $groups, string $normalizedQuery): array
    {
        $n = count($this->index->products);
        $totalWeight = 0.0;
        $scores = [];

        foreach ($groups as $token => $phrases) {
            $match = [];
            foreach ($phrases as $i => $phrase) {
                $quality = $i === 0 ? 1.0 : self::ALIAS_QUALITY;
                foreach ($this->matchPhrase($phrase, $quality) as $id => $m) {
                    $match[$id] = max($match[$id] ?? 0.0, $m);
                }
            }

            // Rare terms matter more (BM25-style idf); a term nothing matches weighs the most
            $df = count($match);
            $weight = log(1 + ($n - $df + 0.5) / ($df + 0.5));
            if (ctype_digit((string) $token)) {
                // Numbers are a bonus when they match but never count against a product: the
                // catalog has no model numbers, so "iPhone 16" should be as precise as "iPhone"
                $weight *= self::NUMBER_WEIGHT;
            } else {
                $totalWeight += $weight;
            }
            foreach ($match as $id => $m) {
                $scores[$id] = ($scores[$id] ?? 0.0) + $weight * $m;
            }
        }

        if ($totalWeight <= 0) {
            return [];
        }
        $phraseBonus = mb_strlen($normalizedQuery) >= 4 && count($groups) > 1;
        foreach ($scores as $id => $score) {
            $score /= $totalWeight;
            if ($phraseBonus && str_contains($this->index->normalizedNames[$id], $normalizedQuery)) {
                $score += self::PHRASE_BONUS;
            }
            $scores[$id] = min(1.0, $score);
        }
        return $scores;
    }

    /**
     * Average match of the phrase's words, so partial matches of multi-word aliases count less.
     *
     * @param list<string> $phrase
     * @return array<string, float>
     */
    private function matchPhrase(array $phrase, float $quality): array
    {
        $sum = [];
        foreach ($phrase as $term) {
            foreach ($this->matchTerm($term, $quality) as $id => $m) {
                $sum[$id] = ($sum[$id] ?? 0.0) + $m;
            }
        }
        return array_map(fn(float $s) => $s / count($phrase), $sum);
    }

    /** @return array<string, float> product id => match quality 0..1 */
    private function matchTerm(string $term, float $quality): array
    {
        $index = $this->index->terms;
        $match = [];
        $add = function (string $key, float $q) use ($index, &$match): void {
            foreach ($index[$key] ?? [] as $id => $fieldWeight) {
                $m = $fieldWeight / CatalogIndexService::MAX_FIELD_WEIGHT * $q;
                if (($match[$id] ?? 0.0) < $m) {
                    $match[$id] = $m;
                }
            }
        };

        foreach (TextNormalizer::variants($term) as $i => $variant) {
            $add($variant, $i === 0 ? $quality : $quality * self::QUERY_VARIANT_QUALITY);
        }
        // Prefix matches cover partially typed words ("שוא" -> "שואב") and compounds
        if (mb_strlen($term) >= 3 && !ctype_digit($term)) {
            foreach ($index as $key => $_) {
                $key = (string) $key;
                if ($key !== $term && str_starts_with($key, $term)) {
                    $add($key, $quality * self::PREFIX_QUALITY);
                }
            }
        }
        return $match;
    }

    /**
     * @param list<float> $queryVector
     * @return array<string, float> product id => 0..1
     */
    private function semanticScores(array $queryVector): array
    {
        $scores = [];
        $range = self::SEM_CEIL - self::SEM_FLOOR;
        foreach ($this->index->products as $id => $_) {
            $packed = $this->index->vectors[$id] ?? null;
            if ($packed === null) {
                $scores[$id] = 0.0;
                continue;
            }
            $dot = Vector::dot(Vector::unpack($packed), $queryVector);
            // Vectors are unit-length, so the dot product is the cosine similarity
            $scores[$id] = max(0.0, min(1.0, ($dot - self::SEM_FLOOR) / $range));
        }
        return $scores;
    }
}
