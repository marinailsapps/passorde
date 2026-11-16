<?php

namespace App\Integrations\Sync;

use App\Enums\SyncStatus;
use Passorde\Sync\RunResult;
use Passorde\Sync\RunStatus;

/**
 * What one sync stores on its sync_runs row.
 */
final readonly class SyncOutcome
{
    public function __construct(
        public SyncStatus $status,
        public int $seen = 0,
        public int $changed = 0,
        public ?string $error = null,
    ) {}

    /**
     * Passorde's results of one run: one, or one per month for a long delivery
     * window. Counts are summed; the first failure wins; skipped only when
     * nothing ran.
     *
     * @param  list<RunResult>  $results
     */
    public static function of(array $results): self
    {
        $seen = array_sum(array_map(fn (RunResult $result): int => $result->seen, $results));
        $changed = array_sum(array_map(fn (RunResult $result): int => $result->changed, $results));

        foreach ($results as $result) {
            if ($result->status === RunStatus::Failed) {
                return new self(SyncStatus::Failed, $seen, $changed, $result->error);
            }
        }

        $ran = array_filter($results, fn (RunResult $result): bool => $result->status !== RunStatus::Skipped);

        return $ran === []
            ? new self(SyncStatus::Skipped)
            : new self(SyncStatus::Succeeded, $seen, $changed);
    }
}
