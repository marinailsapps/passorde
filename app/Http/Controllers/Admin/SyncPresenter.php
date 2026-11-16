<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SyncKind;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Integrations\Sync\Jobs\SyncJob;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Shapes sync_runs for the admin pages and queues manual runs.
 * Web requests never call Evoliz; they only queue a job.
 */
class SyncPresenter
{
    public static function dispatch(SyncKind $kind, Request $request): SyncRun
    {
        $user = $request->user();
        $run = SyncRun::queue($kind, SyncTrigger::Manual, $user instanceof User ? $user : null);
        SyncJob::dispatchFor($run);

        return $run;
    }

    /**
     * Last finished run and whether one is queued or running (the page polls while it is).
     *
     * @return array{last_success_at: string|null, active: bool, last: array<string, mixed>|null}
     */
    public static function status(SyncKind $kind): array
    {
        $last = SyncRun::query()->where('kind', $kind)->latest('id')->first();

        return [
            'last_success_at' => SyncRun::lastSucceeded($kind)?->finished_at?->toIso8601String(),
            'active' => SyncRun::query()
                ->where('kind', $kind)
                ->whereIn('status', [SyncStatus::Queued, SyncStatus::Running])
                ->where('created_at', '>', now()->subHour())
                ->exists(),
            'last' => $last ? self::present($last) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(SyncRun $run): array
    {
        return [
            'id' => $run->id,
            'kind' => $run->kind->value,
            'trigger' => $run->trigger->value,
            'status' => $run->status->value,
            'user' => $run->user?->name,
            'created_at' => $run->created_at->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'duration' => $run->durationSeconds(),
            'rows_seen' => $run->rows_seen,
            'rows_changed' => $run->rows_changed,
            'error' => $run->error,
        ];
    }
}
