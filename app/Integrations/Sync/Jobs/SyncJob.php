<?php

namespace App\Integrations\Sync\Jobs;

use App\Enums\SyncKind;
use App\Enums\SyncStatus;
use App\Integrations\Sync\SyncLicence;
use App\Integrations\Sync\SyncProvider;
use App\Models\SyncRun;
use App\Support\SyncMode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Passorde\Licence\Exceptions\LicenceException;
use Throwable;

/**
 * Runs one sync for an already recorded (queued) sync_runs row: marks it
 * running, asks the SyncProvider, copies the outcome onto it. Failures are
 * recorded on the run, not retried: the next scheduled run picks up.
 * Deliveries take a documentdate window (Y-m-d, Paris, both included); without
 * one, the last 7 days and a month ahead.
 */
class SyncJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public SyncRun $run, public ?string $since = null, public ?string $until = null) {}

    /**
     * Queue a run from a button or `--queue`.
     */
    public static function dispatchFor(SyncRun $run): void
    {
        // The deliveries button: the last 60 days, like the nightly run.
        $run->kind === SyncKind::Deliveries
            ? self::dispatch($run, now('Europe/Paris')->subDays(60)->toDateString())
            : self::dispatch($run);
    }

    public function handle(SyncProvider $provider): void
    {
        self::perform($provider, $this->run, $this->since, $this->until);
    }

    /**
     * Inline run, shared by the job and the console commands.
     */
    public static function perform(SyncProvider $provider, SyncRun $run, ?string $since = null, ?string $until = null): SyncRun
    {
        // Queued while Evoliz ran, run after it stopped (licence, switch, keys): not an error to report.
        if (SyncMode::isFile()) {
            $run->update(['status' => SyncStatus::Skipped, 'started_at' => now(), 'finished_at' => now(), 'error' => SyncLicence::unavailable()]);

            return $run;
        }

        $run->update(['status' => SyncStatus::Running, 'started_at' => now()]);

        try {
            $outcome = $provider->sync(
                $run->kind,
                $since !== null ? Carbon::parse($since, 'Europe/Paris') : null,
                $until !== null ? Carbon::parse($until, 'Europe/Paris') : null,
            );
        } catch (Throwable $e) {
            // An expired or invalid licence is the admin's to renew (banner, digest), not a bug to log.
            if (! $e instanceof LicenceException) {
                report($e);
            }

            $run->update(['status' => SyncStatus::Failed, 'finished_at' => now(), 'error' => Str::limit($e->getMessage(), 2000)]);

            return $run;
        }

        $run->update([
            'status' => $outcome->status,
            'finished_at' => now(),
            'rows_seen' => $outcome->seen,
            'rows_changed' => $outcome->changed,
            'error' => match ($outcome->status) {
                SyncStatus::Skipped => __('Another synchronisation of this kind is already running.'),
                default => $outcome->error === null ? null : Str::limit($outcome->error, 2000),
            },
        ]);

        return $run;
    }
}
