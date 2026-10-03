<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Normalizes and tokenizes mixed Hebrew/English text for keyword matching.
 *
 * Hebrew attaches prefixes (ו, ה, ב, ל, מ, ש, כ) and plural suffixes (ים, ות) to words,
 * so "לכלב", "הכלבים" and "כלב" should all meet. We don't ship a full morphological
 * analyzer; instead each token expands to a few light "stem variants".
 */
final class TextNormalizer
{
    private const HEBREW_PREFIXES = ['ו', 'ה', 'ב', 'ל', 'מ', 'ש', 'כ'];
    private const HEBREW_SUFFIXES = ['ים', 'ות', 'י'];
    private const FINAL_LETTERS = ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ'];

    /** Filler words that carry no product meaning (semantic search still sees the full query). */
    private const STOPWORDS = [
        'של', 'עם', 'את', 'על', 'או', 'גם', 'כל', 'זה', 'זו', 'יש', 'אני', 'לי', 'מה', 'אשר', 'כמו', 'רק',
        'משהו', 'דבר', 'מישהו', 'למישהו', 'שיעזור', 'שעוזר', 'שאוהב', 'שאוהבת', 'אוהב', 'אוהבת', 'צריך',
        'מחפש', 'מחפשת', 'רוצה', 'טוב', 'הכי', 'מאוד', 'בשביל', 'עבור', 'לא', 'אם', 'כדי', 'שלי',
        // Any product can be a gift; the word says why someone buys, not what they buy
        'מתנה', 'מתנות', 'gift', 'gifts', 'present',
        'the', 'a', 'an', 'for', 'of', 'and', 'or', 'with', 'to', 'in', 'on', 'my', 'someone', 'something',
        'who', 'that', 'loves', 'love', 'likes', 'need', 'want', 'best', 'good',
    ];

    /** Words about the request itself, not the product ("מוצרים לכלב" asks for dog products). */
    private const META_WORDS = ['מוצרים', 'מוצר', 'פריטים', 'products', 'product', 'items'];

    /** Joining words: filler, but they don't make a query descriptive ("iphone and similar products"). */
    private const CONNECTORS = ['and', 'or', 'או', 'גם'];

    /** Asking for alternatives: "אייפון ומוצרים דומים", "iphone and similar". */
    private const SIMILAR_WORDS = ['דומים', 'דומה', 'דומות', 'חלופות', 'חלופה', 'אלטרנטיבות', 'similar', 'alternatives', 'alternative'];

    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        // Strip niqqud and cantillation marks
        $text = preg_replace('/[\x{0591}-\x{05C7}]/u', '', $text) ?? $text;
        // Unify geresh/gershayim and quote variants: ג׳ / ג' / ג` -> ג'
        $text = strtr($text, ['׳' => "'", '״' => '"', '`' => "'", '’' => "'", '“' => '"', '”' => '"']);
        return $text;
    }

    /** @return list<string> */
    public static function tokenize(string $text, bool $dropStopwords = false): array
    {
        $text = self::normalize($text);
        // Split letters from digits ("iphone16" -> "iphone 16"), keep decimals like 6.1
        $text = preg_replace('/(?<=\p{L})(?=\d)|(?<=\d)(?=\p{L})/u', ' ', $text) ?? $text;
        preg_match_all("/[\p{L}\d]+(?:['.][\p{L}\d]+)*/u", $text, $m);

        $tokens = [];
        foreach ($m[0] as $token) {
            $token = rtrim($token, "'.");
            if ($token === '' || ($dropStopwords && (self::isStopword($token) || self::isMeta($token)))) {
                continue;
            }
            $tokens[] = $token;
        }
        return $tokens;
    }

    /** A filler word, also with prefixes attached ("ולמישהו", "והכי"). */
    public static function isStopword(string $token): bool
    {
        return (bool) array_intersect(self::prefixBases($token), self::STOPWORDS);
    }

    /** A word about the request rather than the product: "ומוצרים", "דומים", "similar". */
    public static function isMeta(string $token): bool
    {
        return (bool) array_intersect(self::prefixBases($token), [...self::META_WORDS, ...self::SIMILAR_WORDS]);
    }

    public static function isConnector(string $token): bool
    {
        return in_array($token, self::CONNECTORS, true);
    }

    /** @param list<string> $tokens */
    public static function asksForSimilar(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (array_intersect(self::prefixBases($token), self::SIMILAR_WORDS)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Light-stem variants of a token, most specific first. The original token is always first.
     *
     * @return list<string>
     */
    public static function variants(string $token): array
    {
        $variants = [$token];
        if (!preg_match('/^[\x{05D0}-\x{05EA}\']+$/u', $token)) {
            // Latin/digits: only a naive English plural
            if (strlen($token) > 4 && str_ends_with($token, 's')) {
                $variants[] = substr($token, 0, -1);
            }
            return $variants;
        }

        foreach (self::prefixBases($token) as $base) {
            $variants[] = $base;
            foreach (self::HEBREW_SUFFIXES as $suffix) {
                if (str_ends_with($base, $suffix) && mb_strlen($base) - mb_strlen($suffix) >= 3) {
                    $variants[] = mb_substr($base, 0, -mb_strlen($suffix));
                }
            }
        }

        // Final-letter forms collapse so "סירים"->"סיר" and "כלבים"->"כלב" line up with stems
        $variants = array_map(fn(string $v) => self::unfinal($v), $variants);
        array_unshift($variants, $token);
        return array_values(array_unique($variants));
    }

    /**
     * The token and the forms left after removing up to two stacked prefixes ("ולכלב" -> "לכלב", "כלב"),
     * as long as 3+ letters remain.
     *
     * @return list<string>
     */
    private static function prefixBases(string $token): array
    {
        $bases = [$token];
        $current = $token;
        for ($i = 0; $i < 2; $i++) {
            $first = mb_substr($current, 0, 1);
            if (!in_array($first, self::HEBREW_PREFIXES, true) || mb_strlen($current) < 4) {
                break;
            }
            $current = mb_substr($current, 1);
            $bases[] = $current;
        }
        return $bases;
    }

    private static function unfinal(string $word): string
    {
        $last = mb_substr($word, -1);
        return isset(self::FINAL_LETTERS[$last]) ? mb_substr($word, 0, -1) . self::FINAL_LETTERS[$last] : $word;
    }
}
