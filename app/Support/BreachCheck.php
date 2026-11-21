<?php

namespace App\Support;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\NotPwnedVerifier;

/**
 * Laravel's Have I Been Pwned check behind Password::uncompromised() (only the first five characters of
 * the password's SHA-1 leave the server). As in Laravel, a service that cannot be reached lets the
 * password pass; here it is said in one warning line instead of a logged stack trace, and skipped()
 * tells the caller (user:password prints it).
 */
class BreachCheck extends NotPwnedVerifier
{
    private bool $skipped = false;

    public function skipped(): bool
    {
        return $this->skipped;
    }

    /**
     * @param  string  $hashPrefix
     * @return Collection<int, string>
     */
    protected function search($hashPrefix)
    {
        $this->skipped = false;

        try {
            $response = $this->factory->withHeaders(['Add-Padding' => true])
                ->timeout($this->timeout)
                ->get('https://api.pwnedpasswords.com/range/'.$hashPrefix);

            if ($response->successful()) {
                /** @var Collection<int, string> $lines */
                $lines = Str::of($response->body())->trim()->explode("\n")
                    ->filter(fn (string $line): bool => str_contains($line, ':'));

                return $lines;
            }

            $reason = 'HTTP '.$response->status();
        } catch (Exception $e) {
            $reason = $e->getMessage();
        }

        $this->skipped = true;
        Log::warning('Have I Been Pwned unreachable: password breach check skipped.', ['reason' => $reason]);

        return new Collection;
    }
}
