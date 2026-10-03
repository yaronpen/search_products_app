<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Hand-curated aliases for names shoppers type that never appear in the catalog text.
 * The catalog lists e.g. "סמארטפון חכם 6.1 אינץ'" under brand Apple, never "iPhone",
 * and Hebrew brand spellings rarely appear next to the English brand column.
 *
 * Keys are normalized tokens; values are extra terms added to the query (expanded recursively).
 */
final class QueryAliases
{
    private const ALIASES = [
        // Product lines -> brand + product type as the catalog names them
        'אייפון' => 'iphone',
        'איפון' => 'iphone',
        'iphone' => 'apple סמארטפון',
        'אייפד' => 'ipad',
        'איפד' => 'ipad',
        'מקבוק' => 'macbook',
        'איירפודס' => 'airpods',
        'airpods' => 'apple אוזניות',
        'גלקסי' => 'galaxy',
        'galaxy' => 'samsung סמארטפון',
        'פיקסל' => 'pixel',
        'pixel' => 'google סמארטפון',
        'פלייסטיישן' => 'playstation',
        'playstation' => 'ps5',
        'אקסבוקס' => 'xbox',
        'נינטנדו' => 'nintendo',
        'nintendo' => 'switch',
        'קינדל' => 'kindle',
        'kindle' => 'קורא ספרים',
        'נספרסו' => 'nespresso',
        // Hebrew spellings of brands -> the brand column's English name
        'אפל' => 'apple',
        'סמסונג' => 'samsung',
        'שיאומי' => 'xiaomi',
        'דייסון' => 'dyson',
        'פיליפס' => 'philips',
        'בוש' => 'bosch',
        'סוני' => 'sony',
        'גוגל' => 'google',
        'טפאל' => 'tefal',
        'בראון' => 'braun',
        'לוגיטק' => 'logitech',
        'אנקר' => 'anker',
        'דלונגי' => "de'longhi delonghi",
        'קיצנאייד' => 'kitchenaid',
        "קיצ'נאייד" => 'kitchenaid',
        'נינג\'ה' => 'ninja',
        // Common English product words -> catalog's Hebrew wording
        'phone' => 'סמארטפון',
        'smartphone' => 'סמארטפון',
        'טלפון' => 'סמארטפון',
        'פלאפון' => 'סמארטפון',
        'laptop' => 'מחשב נייד',
        'vacuum' => 'שואב אבק',
        'headphones' => 'אוזניות',
        'earbuds' => 'אוזניות',
        'tv' => 'טלוויזיה',
        'coffee' => 'קפה',
    ];

    /**
     * Each token expands to alternative phrases; a product matches a phrase only as far as it
     * matches all of the phrase's words (so "iphone" needs Apple *and* smartphone).
     *
     * @param list<string> $tokens normalized query tokens
     * @return array<string, list<list<string>>> token => alternative phrases (the token itself first)
     */
    public static function expand(array $tokens): array
    {
        $expanded = [];
        foreach ($tokens as $token) {
            $phrases = [[$token]];
            $seen = [$token => true];
            $queue = [$token];
            while ($queue) {
                $alias = self::ALIASES[array_shift($queue)] ?? null;
                if ($alias === null) {
                    continue;
                }
                $phrase = TextNormalizer::tokenize($alias);
                $phrases[] = $phrase;
                // Single-word aliases may alias further ("אייפון" -> "iphone" -> "apple סמארטפון")
                if (count($phrase) === 1 && !isset($seen[$phrase[0]])) {
                    $seen[$phrase[0]] = true;
                    $queue[] = $phrase[0];
                }
            }
            $expanded[$token] = $phrases;
        }
        return $expanded;
    }
}
