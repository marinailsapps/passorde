<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Server-side verification of Cloudflare Turnstile tokens.
 */
class Turnstile
{
    public static function enabled(): bool
    {
        return (bool) config('services.turnstile.enabled');
    }

    public static function siteKey(): ?string
    {
        return self::enabled() ? config('services.turnstile.site_key') : null;
    }

    public static function verify(string $token, ?string $ip = null): bool
    {
        try {
            $response = Http::asForm()->timeout(5)->post((string) config('services.turnstile.verify_url'), array_filter([
                'secret' => config('services.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]));
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful() && $response->json('success') === true;
    }
}
