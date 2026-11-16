<?php

use App\Models\Article;
use App\Models\User;
use App\Support\EvolizState;
use App\Support\SyncMode;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

function evolizKeys(int $company = 0, ?string $public = null, ?string $secret = null): void
{
    config()->set('passorde-connect.evoliz', [...config('passorde-connect.evoliz'), 'company_id' => $company, 'public_key' => $public, 'secret_key' => $secret]);
}

it('follows EVOLIZ_ENABLED alone', function () {
    config()->set('server.evoliz_enabled', false);
    expect(EvolizState::enabled())->toBeFalse()->and(SyncMode::isFile())->toBeTrue();

    config()->set('server.evoliz_enabled', true);
    expect(EvolizState::enabled())->toBeTrue()->and(SyncMode::provider())->toBe('evoliz');
});

it('reads EVOLIZ_ENABLED from the environment as a switch, unset when empty', function (?string $env, ?bool $expected) {
    putenv($env === null ? 'EVOLIZ_ENABLED' : "EVOLIZ_ENABLED={$env}");
    $_ENV['EVOLIZ_ENABLED'] = $_SERVER['EVOLIZ_ENABLED'] = $env;

    try {
        $config = require base_path('config/server.php');
        expect($config['evoliz_enabled'])->toBe($expected);
    } finally {
        putenv('EVOLIZ_ENABLED=true');
        $_ENV['EVOLIZ_ENABLED'] = $_SERVER['EVOLIZ_ENABLED'] = 'true';
    }
})->with([
    'true' => ['true', true],
    'false' => ['false', false],
    '1' => ['1', true],
    '0' => ['0', false],
    'empty, as in .env.example' => ['', null],
]);

describe('EVOLIZ_ENABLED unset: off, whatever else is configured', function () {
    beforeEach(fn () => config()->set('server.evoliz_enabled', null));

    it('stays standalone with Evoliz keys present, all or any one of them', function (int $company, ?string $public, ?string $secret) {
        evolizKeys($company, $public, $secret);

        expect(EvolizState::enabled())->toBeFalse()
            ->and(EvolizState::reason())->toBeNull()
            ->and(SyncMode::provider())->toBe('file');
    })->with([
        'company only' => [1000, null, null],
        'public key only' => [0, 'public', null],
        'secret key only' => [0, null, 'secret'],
        'all' => [1000, 'public', 'secret'],
    ]);

    it('ignores the old SYNC_PROVIDER variable, which nothing reads any more', function () {
        putenv('SYNC_PROVIDER=evoliz');
        $_ENV['SYNC_PROVIDER'] = $_SERVER['SYNC_PROVIDER'] = 'evoliz';

        try {
            $config = require base_path('config/server.php');
            expect($config)->not->toHaveKey('sync_provider')
                ->and(EvolizState::enabled())->toBeFalse()
                ->and(SyncMode::isFile())->toBeTrue();
        } finally {
            putenv('SYNC_PROVIDER');
            unset($_ENV['SYNC_PROVIDER'], $_SERVER['SYNC_PROVIDER']);
        }
    });

    it('is off with no setting and no credentials', function (?string $empty) {
        evolizKeys(0, $empty, $empty);

        expect(EvolizState::enabled())->toBeFalse()->and(SyncMode::provider())->toBe('file');
    })->with(['null' => [null], 'empty string, as in .env.example' => ['']]);
});

it('runs standalone, not half in Evoliz, when a key is missing', function (int $company, ?string $public, ?string $secret) {
    evolizKeys($company, $public, $secret);

    expect(EvolizState::enabled())->toBeTrue()
        ->and(SyncMode::isFile())->toBeTrue()
        ->and(EvolizState::reason())->toBe(EvolizState::REASON_CREDENTIALS);
})->with([
    'company only' => [1000, null, null],
    'no secret' => [1000, 'public', null],
    'no company' => [0, 'public', 'secret'],
]);

it('reports a mismatch only with Evoliz off and an Evoliz catalogue', function () {
    fileMode();
    expect(SyncMode::mismatch())->toBeFalse();

    Article::factory()->create();
    expect(SyncMode::mismatch())->toBeTrue();

    evolizMode();
    expect(SyncMode::mismatch())->toBeFalse();

    // Enabled but standalone (licence): the licence banner says it, not the mismatch.
    licenceExpired();
    expect(SyncMode::isFile())->toBeTrue()->and(SyncMode::mismatch())->toBeFalse();
});

it('keeps the Evoliz mode for the whole existing suite', function () {
    expect(config('server.evoliz_enabled'))->toBeTrue()->and(SyncMode::provider())->toBe('evoliz');
});

it('tells the pages the mode, and keeps the test-mode banner off in file mode', function () {
    config()->set('passorde-connect.writes_enabled', false);
    actingAs(User::factory()->create());

    get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('syncProvider', 'evoliz')->where('evolizWritesEnabled', false)->where('syncMismatch', false));

    fileMode();

    get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('syncProvider', 'file')->where('evolizWritesEnabled', true));
});

it('warns admins only about a mismatch', function () {
    fileMode();
    Article::factory()->create();

    actingAs(User::factory()->create());
    get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('syncMismatch', false));

    actingAs(User::factory()->admin()->create());
    get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('syncMismatch', true));
});
