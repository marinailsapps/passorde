<?php

namespace App\Integrations\Sync\Jobs;

use App\Integrations\Sync\SyncProvider;
use App\Models\Client;
use App\Support\SyncMode;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Creates in Evoliz a client saved without an order, or sends it again from
 * the admin's client page (SyncProvider::pushClient), like the client of an
 * approved order. One attempt at the queue level: transient Evoliz errors are
 * retried inside.
 */
class PushClientJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    public function __construct(public int $clientId) {}

    public function uniqueId(): string
    {
        return 'client-'.$this->clientId;
    }

    public function handle(SyncProvider $provider): void
    {
        $client = Client::query()->find($this->clientId);

        // Evoliz stopped since: the client stays in the app; "Envoyer à Evoliz" once it is back.
        if ($client !== null && ! SyncMode::isFile()) {
            $provider->pushClient($client);
        }
    }

    /**
     * The worker gave up (timeout, crash): the admin must see it.
     */
    public function failed(?Throwable $exception): void
    {
        Client::query()->whereKey($this->clientId)->whereNull('evoliz_client_id')->update([
            'evoliz_error' => __('The push job stopped: :message', ['message' => $exception?->getMessage() ?? '?']),
        ]);
    }
}
