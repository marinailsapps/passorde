<?php

namespace App\Integrations\Sync\Sinks;

/**
 * How the app stores Evoliz text and amounts. The sinks map Passorde records
 * from their raw Evoliz row with these rules, so that a sync of unchanged
 * records moves no updated_at.
 */
final class EvolizText
{
    /**
     * Text Evoliz stores with HTML entities ("SEX&Eacute;E", "&amp;", "&nbsp;"),
     * as plain text for display and search. Never for text sent back to Evoliz.
     */
    public static function plain(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $decoded = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::text(str_replace("\u{00A0}", ' ', $decoded));
    }

    /**
     * Trimmed string with collapsed whitespace, or null when empty.
     */
    public static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $text === '' ? null : $text;
    }

    /**
     * Evoliz designations are HTML fragments ("NAME&nbsp;&nbsp;/ LOT"); store plain text.
     */
    public static function cleanDesignation(mixed $value): string
    {
        $text = html_entity_decode(strip_tags((string) (is_scalar($value) ? $value : '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::text(str_replace("\u{00A0}", ' ', $text)) ?? '';
    }

    /**
     * Two decimals, as the decimal columns hand them back ("12.50").
     */
    public static function money(mixed $value): string
    {
        return number_format(round(is_numeric($value) ? (float) $value : 0.0, 2) + 0.0, 2, '.', '');
    }
}
