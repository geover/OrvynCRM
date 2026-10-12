<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * MySQL/MariaDB FULLTEXT helpers shared by every search box.
 *
 * "Type-ahead" search needs prefix matching ("Bulkdea" finds "Bulkdeal"), which only BOOLEAN MODE offers
 * (+word*). InnoDB ignores words shorter than innodb_ft_min_token_size (default 3, see minWord()), so a term that contains a
 * shorter word is searched with LIKE instead (correct, just not index-assisted).
 */
final class FullText
{
    public static function enabled(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * The BOOLEAN MODE expression for $term ("jane do" -> null because "do" is too short;
     * "jane doe" -> "+jane* +doe*"), or null when the index cannot be used for it.
     */
    public static function booleanQuery(string $term, ?bool $enabled = null): ?string
    {
        $words = self::words($term);
        if ($words === [] || ! ($enabled ?? self::enabled())) {
            return null;
        }
        foreach ($words as $w) {
            if (mb_strlen($w) < self::minWord()) {
                return null;
            }
        }

        return implode(' ', array_map(fn ($w) => '+'.mb_strtolower($w).'*', $words));
    }

    /** Must equal the server's innodb_ft_min_token_size (MySQL default 3); set FULLTEXT_MIN_WORD if you lowered it. */
    public static function minWord(): int
    {
        return max(1, (int) config('app.fulltext_min_word', 3));
    }

    /** Letters and digits only: boolean-mode operators in user input must never reach MATCH. */
    public static function words(string $term): array
    {
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $term) ?: []));
    }
}
