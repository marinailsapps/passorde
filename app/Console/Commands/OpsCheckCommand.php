<?php

namespace App\Console\Commands;

use App\Models\OpsHeartbeat;
use App\Support\Profile\ProfileCheck;
use Illuminate\Console\Command;

/**
 * Container healthcheck: fails when the queue or scheduler heartbeat is older
 * than server.ops.heartbeat_max_minutes (10), or, for `profile`, when the
 * installation profile has an error (passorde:check-profile against the database).
 * compose.prod.yml runs `ops:check queue` in the queue container and
 * `ops:check scheduler` in the scheduler container; `profile` is for a check by
 * hand (deploy.sh runs passorde:check-profile itself, before switching).
 */
class OpsCheckCommand extends Command
{
    protected $signature = 'ops:check {parts?* : queue, scheduler, profile (default: queue and scheduler)}';

    protected $description = 'Exit non-zero when the queue or scheduler heartbeat is stale, or the installation profile has an error';

    public const PROFILE = 'profile';

    public function handle(): int
    {
        $parts = (array) $this->argument('parts') ?: [OpsHeartbeat::QUEUE, OpsHeartbeat::SCHEDULER];
        $maxMinutes = (int) config('server.ops.heartbeat_max_minutes');
        $healthy = true;

        foreach ($parts as $part) {
            if ($part === self::PROFILE) {
                $errors = ProfileCheck::installationErrors();
                $healthy = $healthy && $errors === [];
                foreach ($errors as $error) {
                    $this->error("profile: {$error}");
                }
                if ($errors === []) {
                    $this->line('profile: ok');
                }

                continue;
            }

            if (! in_array($part, [OpsHeartbeat::QUEUE, OpsHeartbeat::SCHEDULER], true)) {
                $this->error("Unknown part: {$part}");

                return self::INVALID;
            }

            $age = OpsHeartbeat::ageInMinutes($part);

            if ($age === null || $age > $maxMinutes) {
                $healthy = false;
                $this->error($age === null ? "{$part}: no heartbeat" : sprintf('%s: last heartbeat %.1f min ago', $part, $age));
            } else {
                $this->line(sprintf('%s: ok (%.1f min)', $part, $age));
            }
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
