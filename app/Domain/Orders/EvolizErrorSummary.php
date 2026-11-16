<?php

namespace App\Domain\Orders;

/**
 * Short French explanation of a failed push, for the operator. The raw text
 * (Passorde's ApiError message, e.g. "HTTP 400 — address.postcode : The
 * postcode must be 5 characters.") stays in orders.evoliz_error and is shown
 * under it as the technical detail. Null when the error is not one of the
 * common cases: the raw text is then all there is.
 */
class EvolizErrorSummary
{
    /** Evoliz field names in "field is required" errors, as the operator knows them. */
    private const FIELDS = [
        'vat_number' => 'VAT number',
        'name' => 'name',
        'email' => 'email',
        'code' => 'client code',
        'type' => 'client type',
    ];

    public static function for(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $sentences = [];

        if (preg_match('/^HTTP 429\b|too many (requests|attempts)/i', $raw) === 1) {
            $sentences[] = __('Evoliz received too many requests in a short time. Retry in a minute.');
        } elseif (self::unreachable($raw)) {
            $sentences[] = __('Evoliz did not answer or was too slow. Retry in a few minutes.');
        }

        if (preg_match('/postcode|postal|zip/i', $raw) === 1) {
            $sentences[] = __('Evoliz refused the client’s postcode (5 digits expected).');
        }
        if (preg_match('/address\.(street|addr|town|city|country)|address\s*:|\btown\b|\bcity\b/i', $raw) === 1) {
            $sentences[] = __('Evoliz refused the client’s address.');
        }
        if (preg_match('/phone|mobile/i', $raw) === 1) {
            $sentences[] = __('Evoliz refused the client’s phone number.');
        }

        $missing = [];
        if (preg_match_all('/(?:^|[—;]\s*)([a-z_]+)\s*:\s*The [a-z ]+ field is required/i', $raw, $matches) > 0) {
            foreach ($matches[1] as $field) {
                if (isset(self::FIELDS[$field])) {
                    $missing[] = __(self::FIELDS[$field]);
                }
            }
        }
        if ($missing !== []) {
            $sentences[] = __('A client field Evoliz requires is missing: :fields.', ['fields' => implode(', ', array_unique($missing))]);
        }

        return $sentences === [] ? null : implode(' ', $sentences);
    }

    private static function unreachable(string $raw): bool
    {
        $didNotAnswer = rtrim(__('Evoliz did not answer.'), '.');

        return str_starts_with($raw, $didNotAnswer)
            || str_starts_with($raw, 'Evoliz did not answer')
            || preg_match('/^HTTP 5\d\d\b|cURL error|timed out|Connection (refused|reset)|Could not resolve/i', $raw) === 1;
    }
}
