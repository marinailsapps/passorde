<?php

use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Pest\TestSuite;
use Tests\TestCase;

use function Pest\Laravel\get;

/**
 * The application booted again with EVOLIZ_ENABLED set as on a server: Passorde Connect reads its switch when it
 * boots (config/passorde-connect.php 'enabled'), so the suite's default (on) has to be replaced before booting.
 */
function bootWithEvolizSwitch(string $value): void
{
    putenv("EVOLIZ_ENABLED={$value}");
    $_ENV['EVOLIZ_ENABLED'] = $value;
    $_SERVER['EVOLIZ_ENABLED'] = $value;

    // The console application keeps the commands of the first boot in a static list.
    ConsoleApplication::forgetBootstrappers();
    $test = TestSuite::getInstance()->test;
    assert($test instanceof TestCase);
    $test->bootAgain();
}

/** @return list<string> */
function addOnCommands(): array
{
    return array_values(array_intersect(
        ['passorde:licence', 'passorde:ping', 'passorde:status', 'passorde:sync'],
        array_keys(Artisan::all()),
    ));
}

function licenceRefreshRegistered(): bool
{
    return collect(app(Schedule::class)->events())
        ->contains(fn ($event) => str_contains((string) $event->command, 'passorde:licence'));
}

afterEach(function () {
    // phpunit.xml's value, for the next test's application.
    putenv('EVOLIZ_ENABLED=true');
    $_ENV['EVOLIZ_ENABLED'] = 'true';
    $_SERVER['EVOLIZ_ENABLED'] = 'true';
    ConsoleApplication::forgetBootstrappers();
});

it('has no licence command, page or scheduled refresh with Evoliz switched off', function (string $off) {
    bootWithEvolizSwitch($off);

    expect(config('passorde-connect.enabled'))->toBeFalse()
        ->and(addOnCommands())->toBe([])
        ->and(Route::has('passorde.licence.show'))->toBeFalse()
        ->and(licenceRefreshRegistered())->toBeFalse();

    get('/passorde/licence')->assertNotFound();
})->with(['false' => 'false', 'empty' => '']);

it('has them all back with Evoliz switched on', function () {
    bootWithEvolizSwitch('true');

    expect(config('passorde-connect.enabled'))->toBeTrue()
        ->and(addOnCommands())->toBe(['passorde:licence', 'passorde:ping', 'passorde:status', 'passorde:sync'])
        ->and(Route::has('passorde.licence.show'))->toBeTrue()
        ->and(licenceRefreshRegistered())->toBeTrue();
});
