<?php

namespace App\Integrations\Sync;

use Generator;
use Illuminate\Http\Client\Response;
use Passorde\Api\Contracts\EvolizApi;

/**
 * Passorde's transport in file mode: every call throws before anything is sent,
 * so that a missed SyncMode branch (or passorde:sync / passorde:ping) fails loudly.
 */
final class FileModeEvolizApi implements EvolizApi
{
    public function get(string $path, array $query = []): array
    {
        throw new SyncFileModeException("GET {$path} called in file mode.");
    }

    public function paginate(string $path, array $query = [], int $perPage = 100): Generator
    {
        throw new SyncFileModeException("GET {$path} called in file mode.");
    }

    public function send(string $method, string $path, array $options = []): Response
    {
        throw new SyncFileModeException(strtoupper($method)." {$path} called in file mode.");
    }
}
