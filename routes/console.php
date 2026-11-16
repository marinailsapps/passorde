<?php

use App\Support\EvolizState;
use App\Support\SyncMode;
use Illuminate\Support\Facades\Schedule;
use Passorde\Licence\LicenceManager;
use Passorde\Licence\LicenceRefresher;

/*
| Evoliz syncs. Times are Europe/Paris. Each command also takes a per-kind
| lock, so a manual run and a scheduled run never overlap. The schedule runs
| only with Evoliz active and server.sync.schedule_enabled: SYNC_SCHEDULE_ENABLED
| when set, else on in production only; manual runs always work. In file mode
| there is nothing to sync.
|
| Every withoutOverlapping() lock expires after a few minutes: a run killed
| with its container (a deploy) would otherwise block its command for 24 hours.
*/

$timezone = (string) config('server.sync.timezone');
$enabled = fn (): bool => (bool) config('server.sync.schedule_enabled') && ! SyncMode::isFile();

// Catalog + stock: every 15 minutes during working hours, hourly otherwise.
Schedule::command('sync:articles --trigger=schedule')
    ->everyFifteenMinutes()
    ->between('6:00', '21:00')
    ->timezone($timezone)
    ->withoutOverlapping(30)
    ->when($enabled);

Schedule::command('sync:articles --trigger=schedule')
    ->hourly()
    ->unlessBetween('6:00', '21:00')
    ->timezone($timezone)
    ->withoutOverlapping(30)
    ->when($enabled);

Schedule::command('sync:clients --trigger=schedule')
    ->dailyAt('02:00')
    ->timezone($timezone)
    ->withoutOverlapping(120)
    ->when($enabled);

// Delivery notes (sales history and report): the last 7 days every 15 minutes
// during working hours, the last 60 days at night (docs/RUNBOOK.md "Delivery mirror").
Schedule::command('sync:deliveries --days=7 --trigger=schedule')
    ->everyFifteenMinutes()
    ->between('6:00', '21:00')
    ->timezone($timezone)
    ->withoutOverlapping(30)
    ->when($enabled);

Schedule::command('sync:deliveries --days=60 --trigger=schedule')
    ->dailyAt('02:45')
    ->timezone($timezone)
    ->withoutOverlapping(60)
    ->when($enabled);

Schedule::command('sync:paytypes --trigger=schedule')
    ->dailyAt('02:30')
    ->timezone($timezone)
    ->withoutOverlapping(30)
    ->when($enabled);

// The Passorde licence (only the licence server, never Evoliz): renewed ahead of its expiry.
// Not gated by server.sync.schedule_enabled, never with Evoliz switched off, and only for a licence that has
// something to renew (Passorde's LicenceRefresher::isRefreshable): one with a refresh token, a subscription or a
// lifetime key (whose updates_until the service extends). A key without token, a missing one or a file that does not
// verify has nothing to ask the server for. Read when the event is due, so a licence installed later is picked up.
// Registered only with Evoliz switched on: switched off, Passorde Connect has no such command (passorde-connect.enabled).
if (config('passorde-connect.enabled') === true) {
    Schedule::command('passorde:licence refresh')
        ->dailyAt('03:15')
        ->timezone($timezone)
        ->withoutOverlapping(30)
        ->when(function (): bool {
            // Evoliz enabled, whatever the licence state: an expired subscription is renewed this way.
            if (! EvolizState::enabled()) {
                return false;
            }

            // Passorde's own rule: any key that carries a refresh token, lifetime keys included (their updates_until is extended).
            return LicenceRefresher::isRefreshable(app(LicenceManager::class)->status());
        });
}

/*
| Operations (docs/RUNBOOK.md "Daily digest"). Not gated: the heartbeats feed
| the container healthchecks (ops:check), and the digest only emails when
| something is wrong.
*/

Schedule::command('ops:heartbeat')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('ops:daily-digest')
    ->dailyAt('07:30')
    ->timezone($timezone)
    ->withoutOverlapping(30);
