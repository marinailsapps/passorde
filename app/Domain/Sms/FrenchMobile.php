<?php

namespace App\Domain\Sms;

/**
 * French mobile numbers (06 / 07) in the forms people type them, normalised
 * to +33XXXXXXXXX. Anything else (landlines, foreign numbers) is not a
 * mobile the order SMS may go to.
 */
final class FrenchMobile
{
    public static function normalise(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        // Spaces, dots, dashes, slashes and brackets are formatting.
        $digits = preg_replace('/[\s.\-\/()]+/u', '', trim($phone)) ?? '';

        if (preg_match('/^(?:\+33|0033|33)?0?([67]\d{8})$/', $digits, $match) !== 1) {
            return null;
        }

        // A bare 9-digit "612345678" is too ambiguous; require 0, +33, 0033 or 33 in front.
        if (strlen($digits) === 9) {
            return null;
        }

        return '+33'.$match[1];
    }

    /**
     * The first of the given numbers that is a French mobile.
     */
    public static function first(?string ...$phones): ?string
    {
        foreach ($phones as $phone) {
            $mobile = self::normalise($phone);

            if ($mobile !== null) {
                return $mobile;
            }
        }

        return null;
    }

    /**
     * +33612345678 → 06 12 34 56 78, for the admin screens.
     */
    public static function display(string $mobile): string
    {
        return trim(chunk_split('0'.substr($mobile, 3), 2, ' '));
    }
}
