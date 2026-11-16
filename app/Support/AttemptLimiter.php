<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Limits form submissions to 5 per minute, both per client IP and per
 * identifier. Exceeding the limit is reported as a validation error on the
 * given field.
 *
 * Login counts failed attempts only (failed() / succeeded()), so an office
 * where everyone signs in behind one address is never locked out by its own
 * successful logins. Forms that send something on every submission (reset
 * links) count each one with hit().
 */
class AttemptLimiter
{
    public const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function __construct(
        private readonly string $name,
        private readonly Request $request,
        private readonly string $identifier,
    ) {}

    public static function for(string $name, Request $request, string $identifier): self
    {
        return new self($name, $request, mb_strtolower(trim($identifier)));
    }

    /**
     * Fail if either bucket is already full; counts nothing.
     *
     * @throws ValidationException
     */
    public function ensureNotLimited(string $field): void
    {
        foreach ($this->keys() as $key) {
            if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                throw ValidationException::withMessages([
                    $field => __('Too many attempts. Please try again in :seconds seconds.', [
                        'seconds' => RateLimiter::availableIn($key),
                    ]),
                ]);
            }
        }
    }

    /**
     * Count this attempt, or fail if the limit is already reached.
     *
     * @throws ValidationException
     */
    public function hit(string $field): void
    {
        $this->ensureNotLimited($field);
        $this->failed();
    }

    /** Count a failed attempt in both buckets. */
    public function failed(): void
    {
        foreach ($this->keys() as $key) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
        }
    }

    /** A successful attempt forgives the earlier failures of this IP and identifier. */
    public function succeeded(): void
    {
        foreach ($this->keys() as $key) {
            RateLimiter::clear($key);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function keys(): array
    {
        return [
            "{$this->name}|ip:{$this->request->ip()}",
            "{$this->name}|id:{$this->identifier}",
        ];
    }
}
