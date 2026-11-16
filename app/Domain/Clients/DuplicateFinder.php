<?php

namespace App\Domain\Clients;

use App\Models\Client;

/**
 * Finds an existing client that looks like a new one: same postcode and a
 * company name with pg_trgm similarity above 0.6. A warning, never a block.
 */
class DuplicateFinder
{
    public const THRESHOLD = 0.6;

    public static function find(string $companyName, ?string $postcode, ?int $exceptId = null): ?Client
    {
        $companyName = trim($companyName);

        if ($companyName === '' || $postcode === null || trim($postcode) === '') {
            return null;
        }

        return Client::query()
            ->where('postcode', trim($postcode))
            ->where('is_active', true)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->whereRaw('similarity(company_name, ?) > ?', [$companyName, self::THRESHOLD])
            ->orderByRaw('similarity(company_name, ?) desc', [$companyName])
            ->first();
    }
}
