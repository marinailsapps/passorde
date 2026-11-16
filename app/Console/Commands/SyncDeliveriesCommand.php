<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RefusesInFileMode;
use App\Enums\SyncKind;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Integrations\Sync\Jobs\SyncJob;
use App\Integrations\Sync\PassordeProvider;
use App\Integrations\Sync\SyncProvider;
use App\Models\SyncRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Mirrors Evoliz delivery notes by documentdate. A window longer than the
 * nightly one (the backfill, docs/RUNBOOK.md "Delivery mirror") runs as one sync run per
 * calendar month, so each stays well inside the sync lock and a failure only
 * costs its month.
 */
class SyncDeliveriesCommand extends Command
{
    use RefusesInFileMode;

    protected $signature = 'sync:deliveries
        {--since= : First documentdate (Y-m-d); default today minus --days}
        {--until= : Last documentdate (Y-m-d); default a month ahead}
        {--days=7 : Without --since, how many days back}
        {--queue : Dispatch the jobs to the queue instead of running inline}
        {--trigger=console : Recorded trigger (console, schedule)}';

    private const MAX_DAYS = 100;

    /** @var list<string> Kept for one release, until the RUNBOOK and habits move. */
    protected $aliases = ['evoliz:sync-deliveries'];

    protected $description = 'Mirror Evoliz delivery notes (BL) of a date window and record the runs in sync_runs';

    public function handle(): int
    {
        if ($this->refusedInFileMode()) {
            return self::FAILURE;
        }

        $today = Carbon::today('Europe/Paris');
        $since = $this->option('since') ? Carbon::createFromFormat('Y-m-d', (string) $this->option('since'), 'Europe/Paris') : $today->copy()->subDays((int) $this->option('days'));
        $until = $this->option('until') ? Carbon::createFromFormat('Y-m-d', (string) $this->option('until'), 'Europe/Paris') : $today->copy()->addDays(PassordeProvider::AHEAD_DAYS);

        if (! $since || ! $until || $since->gt($until)) {
            $this->error('Invalid window: --since and --until are Y-m-d, since before until.');

            return self::INVALID;
        }

        $trigger = SyncTrigger::tryFrom((string) $this->option('trigger')) ?? SyncTrigger::Console;
        $windows = self::windows($since->startOfDay(), $until->startOfDay());
        $failed = false;
        $totals = ['seen' => 0, 'changed' => 0];
        $started = microtime(true);

        foreach ($windows as [$from, $to]) {
            $run = SyncRun::queue(SyncKind::Deliveries, $trigger);

            if ($this->option('queue')) {
                SyncJob::dispatch($run, $from->toDateString(), $to->toDateString());
                $this->info("Queued sync run #{$run->id} ({$from->toDateString()} → {$to->toDateString()}).");

                continue;
            }

            $run = SyncJob::perform(app(SyncProvider::class), $run, $from->toDateString(), $to->toDateString());
            $failed = $failed || $run->status === SyncStatus::Failed;
            $totals['seen'] += $run->rows_seen;
            $totals['changed'] += $run->rows_changed;

            $this->line(sprintf(
                'Sync run #%d %s → %s %s: %d seen, %d changed%s',
                $run->id,
                $from->toDateString(),
                $to->toDateString(),
                $run->status->value,
                $run->rows_seen,
                $run->rows_changed,
                $run->error ? " — {$run->error}" : '',
            ));
        }

        if (! $this->option('queue') && count($windows) > 1) {
            $this->info(sprintf('%d windows: %d seen, %d changed in %d s.', count($windows), $totals['seen'], $totals['changed'], (int) round(microtime(true) - $started)));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * One window up to MAX_DAYS (the nightly 60 days plus a month ahead), else one per calendar month.
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    public static function windows(Carbon $since, Carbon $until): array
    {
        if ($since->diffInDays($until) <= self::MAX_DAYS) {
            return [[$since, $until]];
        }

        $windows = [];
        $from = $since->copy();

        while ($from->lte($until)) {
            $to = $from->copy()->endOfMonth()->startOfDay()->min($until);
            $windows[] = [$from, $to];
            $from = $to->copy()->addDay();
        }

        return $windows;
    }
}
