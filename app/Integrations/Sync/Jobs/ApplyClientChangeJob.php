<?php

namespace App\Integrations\Sync\Jobs;

use App\Integrations\Sync\SyncLicence;
use App\Integrations\Sync\SyncProvider;
use App\Models\ClientChange;
use App\Support\SyncMode;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends again a client correction that Evoliz refused when its order was
 * pushed ("Réessayer" in the client's history, SyncProvider::retryClientChange).
 * Its requests are logged in passorde_requests with the order.
 */
class ApplyClientChangeJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    public function __construct(public int $changeId) {}

    public function uniqueId(): string
    {
        return 'client-change-'.$this->changeId;
    }

    public function handle(SyncProvider $provider): void
    {
        $change = ClientChange::query()->find($this->changeId);

        if ($change === null) {
            return;
        }

        // Evoliz stopped since: the correction keeps its "Réessayer" (admins only).
        if (SyncMode::isFile()) {
            $change->update(['error' => SyncLicence::unavailable()]);

            return;
        }

        $provider->retryClientChange($change);
    }
}
