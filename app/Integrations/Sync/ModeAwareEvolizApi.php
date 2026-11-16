<?php

namespace App\Integrations\Sync;

use App\Support\SyncMode;
use Generator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Response;
use Passorde\Api\Contracts\EvolizApi;
use Passorde\Api\EvolizClient;

/**
 * Passorde's transport as the container hands it out (one instance, which
 * Passorde's verbs and runners may keep): it asks SyncMode at every call, so the
 * file-mode tripwire (FileModeEvolizApi) holds in a long-running worker after a
 * lapse or a key removal, and the Evoliz client comes back once Evoliz runs again.
 */
final class ModeAwareEvolizApi implements EvolizApi
{
    private ?EvolizClient $client = null;

    private readonly FileModeEvolizApi $tripwire;

    public function __construct(private readonly Application $app)
    {
        $this->tripwire = new FileModeEvolizApi;
    }

    public function get(string $path, array $query = []): array
    {
        return $this->target()->get($path, $query);
    }

    public function paginate(string $path, array $query = [], int $perPage = 100): Generator
    {
        return $this->target()->paginate($path, $query, $perPage);
    }

    public function send(string $method, string $path, array $options = []): Response
    {
        return $this->target()->send($method, $path, $options);
    }

    private function target(): EvolizApi
    {
        if (SyncMode::isFile()) {
            return $this->tripwire;
        }

        // Made once: it reads the keys from the configuration at each call (EvolizSettings::apply).
        return $this->client ??= $this->app->make(EvolizClient::class);
    }
}
