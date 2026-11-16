<?php

use Illuminate\Console\Scheduling\Schedule;

/*
| One switch: on a server EVOLIZ_ENABLED is enough for the scheduled syncs;
| a laptop (whose .env may hold real Evoliz keys) never starts syncing by itself.
*/

/**
 * config/server.php evaluated with these environment values (null: unset).
 */
function scheduleEnabledWith(?string $appEnv, ?string $schedule): bool
{
    $saved = [];
    foreach (['APP_ENV' => $appEnv, 'SYNC_SCHEDULE_ENABLED' => $schedule] as $key => $value) {
        $saved[$key] = getenv($key);
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    try {
        return (require base_path('config/server.php'))['sync']['schedule_enabled'];
    } finally {
        foreach ($saved as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
    }
}

it('turns the schedule on in production and off elsewhere when SYNC_SCHEDULE_ENABLED is unset or empty', function (string $appEnv, ?string $schedule, bool $expected) {
    expect(scheduleEnabledWith($appEnv, $schedule))->toBe($expected);
})->with([
    'production, unset' => ['production', null, true],
    'production, empty' => ['production', '', true],
    'local, unset' => ['local', null, false],
    'local, empty' => ['local', '', false],
    'testing, unset' => ['testing', null, false],
]);

it('lets SYNC_SCHEDULE_ENABLED decide when it is set', function (string $appEnv, string $schedule, bool $expected) {
    expect(scheduleEnabledWith($appEnv, $schedule))->toBe($expected);
})->with([
    'production, false' => ['production', 'false', false],
    'production, true' => ['production', 'true', true],
    'local, true' => ['local', 'true', true],
    'local, false (.env.example)' => ['local', 'false', false],
    'local, 0' => ['local', '0', false],
    'production, 1' => ['production', '1', true],
]);

it('runs the scheduled syncs on a server where only Evoliz is switched on', function () {
    config()->set('server.sync.schedule_enabled', scheduleEnabledWith('production', null));
    evolizMode();

    $due = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, "'artisan' sync:") && $event->filtersPass(app()));

    expect($due)->not->toBeEmpty();

    // The same server with Evoliz switched off: nothing is synced.
    fileMode();
    expect($due->filter(fn ($event) => $event->filtersPass(app())))->toBeEmpty();
});

it('runs no scheduled sync on a laptop with real Evoliz keys and the schedule left empty', function () {
    config()->set('server.sync.schedule_enabled', scheduleEnabledWith('local', ''));
    evolizMode();

    $due = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, "'artisan' sync:") && $event->filtersPass(app()));

    expect($due)->toBeEmpty();
});
