<?php

namespace App\Rules;

use App\Domain\Sms\FrenchMobile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A French phone number as people type it (06 12 34 56 78, +33 9 70 ...),
 * or with $mobile only an 06 / 07 one. Evoliz stores any text, so the app
 * checks before a correction is sent (resources/js/lib/frenchPhone.ts does the same).
 */
class FrenchPhone implements ValidationRule
{
    public function __construct(private readonly bool $mobile = false) {}

    public static function isValid(string $value, bool $mobile = false): bool
    {
        if ($mobile) {
            return FrenchMobile::normalise($value) !== null;
        }

        $digits = preg_replace('/[\s.\-\/()]+/u', '', trim($value)) ?? '';

        return preg_match('/^(?:0|\+33|0033)[1-9]\d{8}$/', $digits) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value, $this->mobile)) {
            $fail($this->mobile ? __('Enter a French mobile number (06 or 07).') : __('Enter a French phone number.'));
        }
    }
}
