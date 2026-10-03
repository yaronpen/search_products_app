<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\JsonResponse;
use App\Core\Request;
use App\Models\Product;
use App\Services\SearchService;

/** HTTP layer only: validates input, calls the service, shapes the JSON. */
final class SearchController
{
    private const MAX_QUERY_LENGTH = 200;
    private const DEFAULT_LIMIT = 24;
    private const MAX_LIMIT = 48;

    public function __construct(private readonly SearchService $search)
    {
    }

    // GET /api/search?q=...&limit=24[&debug]
    public function search(Request $request): JsonResponse
    {
        $started = hrtime(true);
        $rawQuery = $request->query('q', '');

        // Checked first: the cleanup regex below (like json_encode later) fails on invalid UTF-8
        if (!mb_check_encoding($rawQuery, 'UTF-8')) {
            return JsonResponse::error(400, 'invalid_encoding', 'החיפוש מכיל תווים לא תקינים');
        }
        $query = self::cleanQuery($rawQuery);
        $limit = self::parseLimit($request->query('limit'));

        if ($query === '') {
            return JsonResponse::error(400, 'missing_query', 'יש להזין מילת חיפוש');
        }
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return JsonResponse::error(400, 'query_too_long', 'החיפוש ארוך מדי (עד 200 תווים)');
        }

        $result = $this->search->search($query, $limit);

        $results = $result['results'];
        if ($request->has('debug')) {
            // Expose ranking internals for tuning: ?debug adds each result's score breakdown
            $results = array_map(
                fn(Product $p) => $p->jsonSerialize() + ['_scores' => $result['scores'][$p->id]],
                $results,
            );
        }

        return new JsonResponse([
            'query' => $query,
            'count' => count($results),
            'results' => $results,
            'mode' => $result['mode'],
            'warning' => $result['warning'],
            'took_ms' => (int) round((hrtime(true) - $started) / 1e6),
        ]);
    }

    /**
     * Removes invisible characters (control codes, and format marks such as RLM/LRM that often
     * come along with pasted Hebrew) and collapses whitespace, so "\0\1" counts as empty and
     * "שואב  אבק" hits the same cached query vector as "שואב אבק".
     */
    private static function cleanQuery(string $query): string
    {
        $query = preg_replace('/\p{Cf}+/u', '', $query);
        $query = preg_replace('/[\p{Cc}\s]+/u', ' ', $query);
        return trim($query);
    }

    /** Missing or not a whole number -> default; out of range -> clamped to 1..MAX_LIMIT. */
    private static function parseLimit(?string $raw): int
    {
        $limit = $raw === null ? false : filter_var($raw, FILTER_VALIDATE_INT);
        if ($limit === false) {
            return self::DEFAULT_LIMIT;
        }
        return max(1, min(self::MAX_LIMIT, $limit));
    }
}
