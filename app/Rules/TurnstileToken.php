<?php

namespace App\Rules;

use App\Support\Turnstile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class TurnstileToken implements ValidationRule
{
    /**
     * Validation rules for the `turnstile_token` form field; empty when Turnstile is disabled.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return Turnstile::enabled()
            ? ['turnstile_token' => ['required', 'string', new self]]
            : [];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Turnstile::verify($value, request()->ip())) {
            $fail(__('The anti-robot check failed. Please try again.'));
        }
    }
}
