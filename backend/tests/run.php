<?php
declare(strict_types=1);

/**
 * Tests for the parts where mistakes are silent: text normalization, aliases, prices,
 * and search relevance against a golden set of queries.
 *
 *   php tests/run.php            # all tests (relevance needs the DB, and OPENAI_API_KEY for semantic cases)
 *   php tests/run.php --verbose  # also print the top results per query
 */

use App\Controllers\SearchController;
use App\Core\App;
use App\Core\JsonResponse;
use App\Core\Request;
use App\Models\Product;
use App\Services\PriceEstimator;
use App\Support\QueryAliases;
use App\Support\TextNormalizer;

/** @var App $app */
$app = require __DIR__ . '/../bootstrap/app.php';

$verbose = in_array('--verbose', $argv, true);
$failures = 0;
$passes = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $passes;
    $ok ? $passes++ : $failures++;
    echo ($ok ? "  \e[32m✓\e[0m " : "  \e[31m✗\e[0m ") . $name . ($ok || $detail === '' ? '' : " — {$detail}") . "\n";
}

// --- Normalization ---
echo "TextNormalizer\n";
check('lowercases and splits letters from digits', TextNormalizer::tokenize('iPhone16') === ['iphone', '16']);
check('keeps decimals and geresh words (trailing geresh dropped)', TextNormalizer::tokenize("6.1 אינץ' ג׳ל") === ['6.1', 'אינץ', "ג'ל"]);
check('drops filler words', TextNormalizer::tokenize('משהו שיעזור לנקות שערות של כלב', true) === ['לנקות', 'שערות', 'כלב']);
check('strips niqqud', TextNormalizer::normalize('שָׁלוֹם') === 'שלום');
check('prefix variant: לכלב -> כלב', in_array('כלב', TextNormalizer::variants('לכלב'), true));
check('plural variant: כלבים -> כלב', in_array('כלב', TextNormalizer::variants('כלבים'), true));
check('stacked prefixes: ולכלבים -> כלב', in_array('כלב', TextNormalizer::variants('ולכלבים'), true));
check('final letters: סירים -> סיר', in_array('סיר', TextNormalizer::variants('סירים'), true));
check('short words are not over-stripped: שמן stays', !in_array('מן', TextNormalizer::variants('שמן'), true));
check('english plural: headphones -> headphone', in_array('headphone', TextNormalizer::variants('headphones'), true));
check('stopwords match with prefixes: ולמישהו', TextNormalizer::isStopword('ולמישהו'));
check('request words are dropped: אייפון ומוצרים דומים -> אייפון', TextNormalizer::tokenize('אייפון ומוצרים דומים', true) === ['אייפון']);
check('asks for similar: ומוצרים דומים / similar', TextNormalizer::asksForSimilar(['ומוצרים', 'דומים']) && TextNormalizer::asksForSimilar(['similar']));
check('a plain query does not ask for similar', !TextNormalizer::asksForSimilar(['אייפון', '16']));

echo "QueryAliases\n";
check('אייפון -> iphone -> apple + smartphone', QueryAliases::expand(['אייפון'])['אייפון'] === [['אייפון'], ['iphone'], ['apple', 'סמארטפון']]);
check('unknown words have no aliases', QueryAliases::expand(['מחבת'])['מחבת'] === [['מחבת']]);

echo "PriceEstimator\n";
$p1 = PriceEstimator::estimate('P0001', 'מטהג אוויר', 'מכשירי חשמל קטנים למטבח');
check('deterministic', $p1 === PriceEstimator::estimate('P0001', 'מטהג אוויר', 'מכשירי חשמל קטנים למטבח'));
check('within subcategory range', $p1 >= 199 && $p1 <= 2490, (string) $p1);
check('retail rounding (ends in 9 or 90)', in_array((int) $p1 % 10, [9, 0], true), (string) $p1);
check('premium wording costs more than budget wording',
    PriceEstimator::estimate('X1', 'סמארטפון פרו', 'סמארטפונים') > PriceEstimator::estimate('X1', 'סמארטפון תקציבי', 'סמארטפונים'));

// --- Input validation (controller called directly, no HTTP server needed) ---
echo "SearchController input validation\n";
$controller = new SearchController($app->search);
$call = fn(array $query) => $controller->search(new Request('GET', '/api/search', $query));
$errorOf = fn(array $query) => $call($query)->body['error'] ?? null;

check('missing q -> missing_query', $errorOf([]) === 'missing_query');
check('whitespace-only q -> missing_query', $errorOf(['q' => "   \t\n "]) === 'missing_query');
check('control characters only -> missing_query', $errorOf(['q' => "\0\x01\x1F"]) === 'missing_query');
check('format marks only (RLM/LRM) -> missing_query', $errorOf(['q' => "\u{200F}\u{200E}"]) === 'missing_query');
check('array instead of string -> missing_query', $errorOf(['q' => ['abc']]) === 'missing_query');
check('invalid UTF-8 -> 400 invalid_encoding', $call(['q' => "\xFF\xFE"])->status === 400 && $errorOf(['q' => "\xFF\xFE"]) === 'invalid_encoding');
check('201 chars -> query_too_long', $errorOf(['q' => str_repeat('a', 201)]) === 'query_too_long');
check('200 Hebrew chars is allowed (counts chars, not bytes)', $errorOf(['q' => str_repeat('א', 200)]) === null);
check('control chars inside a query are cleaned', $call(['q' => "שואב\0 \u{200F}אבק"])->body['query'] === 'שואב אבק');
$limitOf = fn(?string $limit) => $call(['q' => 'שואב אבק'] + ($limit === null ? [] : ['limit' => $limit]))->body['count'];
check('limit=abc falls back to the default', $limitOf('abc') === $limitOf(null) && $limitOf(null) > 1);
check('limit=-5 is clamped to 1', $limitOf('-5') === 1);
check('limit=2.5 (not a whole number) falls back to the default', $limitOf('2.5') === $limitOf(null));
check('limit=3 is respected', $limitOf('3') === 3);

echo "JsonResponse\n";
check('unencodable body becomes a 500, not an empty 200', (new JsonResponse(['q' => "\xFF"]))->encode()[0] === 500);
check('normal body encodes with its status', (new JsonResponse(['a' => 'א'], 201))->encode() === [201, '{"a":"א"}']);

// --- Relevance (golden queries) ---
$semantic = $app->embeddings->isEnabled() && $app->catalogIndex->get()->hasVectors();
echo "\nRelevance (" . ($semantic ? 'hybrid' : 'keyword-only; semantic cases skipped') . ")\n";

/**
 * [query, needs semantic?, check(list<Product>) => bool]
 */
$inSub = fn(string $sub) => fn(Product $p) => $p->subcategory === $sub;
// Vacuums also live outside their subcategory (a car vacuum under "רכב", a wet floor vacuum under "ניקוי")
$isVacuum = fn(Product $p) => $p->subcategory === 'שואבי אבק ורובוטים' || str_contains($p->name, 'שואב');
$cases = [
    ['שואב אבק', false, fn($rs) => count($rs) >= 15 && allOf($rs, $isVacuum)],
    ['שואבי אבק', false, fn($rs) => allOf(array_slice($rs, 0, 5), $isVacuum)],
    ['vacuum cleaner', true, fn($rs) => allOf(array_slice($rs, 0, 5), $isVacuum)],
    // Precise queries return only the named products, not every similar one
    ['iPhone 16', false, fn($rs) => ids($rs) == ['P0401', 'P0407', 'P0413']],
    ['iphone', false, fn($rs) => ids($rs) == ['P0401', 'P0407', 'P0413']],
    ['אייפון', false, fn($rs) => ids($rs) == ['P0401', 'P0407', 'P0413']],
    // Keyword-only can't fully separate a Samsung fridge from a Samsung phone (both match the brand);
    // the fallback must still rank the phones first, and hybrid mode must return only them
    ['סמסונג גלקסי', false, fn($rs) => ids(array_slice($rs, 0, 3)) == ['P0402', 'P0406', 'P0410']],
    ['סמסונג גלקסי', true, fn($rs) => ids($rs) == ['P0402', 'P0406', 'P0410']],
    // Asking for alternatives: the named products first, then the rest of their type (all smartphones)
    ['אייפון ומוצרים דומים', false, fn($rs) => ids(array_slice($rs, 0, 3)) == ['P0401', 'P0407', 'P0413'] && count($rs) === 13 && allOf($rs, $inSub('סמארטפונים'))],
    ['iphone and similar products', false, fn($rs) => ids(array_slice($rs, 0, 3)) == ['P0401', 'P0407', 'P0413'] && count($rs) === 13 && allOf($rs, $inSub('סמארטפונים'))],
    ['מחבת ומוצרים דומים', false, fn($rs) => countOf($rs, fn(Product $r) => str_contains($r->name, 'מחבת')) >= 6 && allOf($rs, $inSub('סירים ומחבתות')) && count($rs) > 6],
    ['מוצרים לכלב', false, fn($rs) => countOf(array_slice($rs, 0, 5), $inSub('כלבים')) >= 4],
    ['macbook', false, fn($rs) => allOf($rs, fn(Product $r) => $r->brand === 'Apple' && str_contains($r->name, 'MacBook'))],
    ['ps5', false, fn($rs) => $rs && $rs[0]->id === 'P0551'],
    ['מחבת', false, fn($rs) => allOf(array_slice($rs, 0, 6), $inSub('סירים ומחבתות'))],
    ['Air Fryer', false, fn($rs) => in_array($rs[0]->id ?? '', ['P0001', 'P0002'], true)],
    ['מתנה למישהו שאוהב לבשל', true,
        fn($rs) => countOf(array_slice($rs, 0, 8), fn(Product $r) => $r->category === 'מטבח ובישול') >= 6],
    ['מתנה למישהו שאוהב קפה', true,
        fn($rs) => countOf(array_slice($rs, 0, 8), fn(Product $r) => $r->category === 'קפה, משקאות וגורמה') >= 6],
    ['משהו שיעזור לנקות שערות של כלב מהספה', true,
        fn($rs) => countOf(array_slice($rs, 0, 6), fn(Product $r) => in_array($r->id, ['P0207', 'P0950', 'P0214', 'P0212', 'P0225', 'P0218', 'P0208', 'P0201', 'P0210', 'P0209'], true)) >= 3
            && countOf(array_slice($rs, 0, 3), fn(Product $r) => in_array($r->subcategory, ['הסרת שיער וטיפוח פנים', 'עיצוב שיער', 'ריהוט'], true)) === 0],
    ['something to help me sleep better', true,
        fn($rs) => countOf(array_slice($rs, 0, 6), fn(Product $r) => in_array($r->subcategory, ['שינה ורוגע', 'מצעים ושינה'], true)) >= 4],
    ['אוזניות sony', false, fn($rs) => $rs && $rs[0]->brand === 'Sony' && str_contains($rs[0]->name, 'אוזניות')],
    ['שואב אבק לרכב', false, fn($rs) => in_array($rs[0]->id ?? '', ['P0212', 'P0987', 'P0207'], true)],
    ['qwxzkj', false, fn($rs) => $rs === []],
];

foreach ($cases as [$query, $needsSemantic, $assert]) {
    if ($needsSemantic && !$semantic) {
        echo "  - {$query} (skipped)\n";
        continue;
    }
    $start = hrtime(true);
    $response = $app->search->search($query, 24);
    $results = $response['results'];
    $ms = (hrtime(true) - $start) / 1e6;
    check(sprintf('%s  [%d results, %.0f ms]', $query, count($results), $ms), $assert($results) === true,
        'top: ' . implode(' | ', array_map(fn(Product $r) => "{$r->id} {$r->name}", array_slice($results, 0, 5))));
    if ($verbose) {
        foreach (array_slice($results, 0, 8) as $r) {
            $s = $response['scores'][$r->id];
            printf("      %s %.3f (lex %.2f, sem %s)  %s\n", $r->id, $s['score'], $s['lexical'], $s['semantic'] ?? '-', $r->name);
        }
    }
}

echo "\n{$passes} passed, {$failures} failed\n";
exit($failures ? 1 : 0);

function ids(array $rows): array { $ids = array_map(fn(Product $p) => $p->id, $rows); sort($ids); return $ids; }
function allOf(array $rows, callable $fn): bool { return $rows !== [] && count(array_filter($rows, $fn)) === count($rows); }
function countOf(array $rows, callable $fn): int { return count(array_filter($rows, $fn)); }
