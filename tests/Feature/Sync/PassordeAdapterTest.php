<?php

use App\Enums\SyncKind;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Integrations\Sync\FileSyncProvider;
use App\Integrations\Sync\Jobs\SyncJob;
use App\Integrations\Sync\ModeAwareEvolizApi;
use App\Integrations\Sync\PassordeProvider;
use App\Integrations\Sync\Sinks;
use App\Integrations\Sync\SyncFileModeException;
use App\Integrations\Sync\SyncOutcome;
use App\Integrations\Sync\SyncProvider;
use App\Models\Article;
use App\Models\EvolizDelivery;
use App\Models\SyncRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Passorde\Api\Contracts\EvolizApi;
use Passorde\Sinks\Contracts;
use Passorde\Sync\RunResult;
use Passorde\Sync\RunStatus;
use Passorde\Sync\SyncKind as PassordeKind;

const ADAPTER_ARTICLE_PAGES = ['articles' => ['articles-page-1', 'articles-page-2']];

function runSync(SyncKind $kind, ?string $since = null, ?string $until = null): SyncRun
{
    $run = SyncRun::queue($kind, SyncTrigger::Console);
    SyncJob::dispatchSync($run, $since, $until);

    return $run->fresh();
}

it('feeds the app\'s own tables through its sinks, never the passorde_ mirror', function () {
    expect(app(SyncProvider::class))->toBeInstanceOf(PassordeProvider::class)
        ->and(app(Contracts\ArticleSink::class))->toBeInstanceOf(Sinks\ArticleSink::class)
        ->and(app(Contracts\ClientSink::class))->toBeInstanceOf(Sinks\ClientSink::class)
        ->and(app(Contracts\PaytypeSink::class))->toBeInstanceOf(Sinks\PaytypeSink::class)
        ->and(app(Contracts\UserSink::class))->toBeInstanceOf(Sinks\UserSink::class)
        ->and(app(Contracts\DeliverySink::class))->toBeInstanceOf(Sinks\DeliverySink::class);

    fakeEvoliz(ADAPTER_ARTICLE_PAGES);

    expect(runSync(SyncKind::Articles)->status)->toBe(SyncStatus::Succeeded)
        ->and(Article::query()->count())->toBeGreaterThan(0)
        ->and(DB::table('passorde_articles')->count())->toBe(0)
        ->and(DB::table('passorde_runs')->where('kind', 'articles')->value('status'))->toBe('succeeded');
});

it('still syncs while the licence is in its grace', function () {
    fakeEvoliz(ADAPTER_ARTICLE_PAGES);
    licenceGrace();

    expect(runSync(SyncKind::Articles)->status)->toBe(SyncStatus::Succeeded);
});

it('skips the run without calling Evoliz when the licence is expired or invalid', function (string $licence) {
    fakeEvoliz(ADAPTER_ARTICLE_PAGES);
    $licence === 'expired' ? licenceExpired() : licenceInvalid();

    $run = runSync(SyncKind::Articles);

    expect($run->status)->toBe(SyncStatus::Skipped)
        ->and($run->error)->not->toBeEmpty()
        ->and(Article::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['expired', 'invalid']);

it('records a delivery window longer than 100 days as one run, the months summed', function () {
    $deliveries = evolizFixture('deliveries-window');
    scriptEvoliz([
        'GET /deliveries' => fn () => Http::response($deliveries, 200, ['X-RateLimit-Remaining' => '90']),
        'GET /users' => evolizJson(evolizFixture('users')),
    ]);

    // 2026-06-01 → 2026-09-30: 122 days, four calendar months; Evoliz answers the same two BLs each time.
    $run = runSync(SyncKind::Deliveries, '2026-06-01', '2026-09-30');

    expect(SyncRun::query()->count())->toBe(1)
        ->and($run->status)->toBe(SyncStatus::Succeeded)
        ->and($run->rows_seen)->toBe(8)
        ->and($run->rows_changed)->toBe(2)
        ->and(EvolizDelivery::query()->count())->toBe(2)
        ->and(DB::table('passorde_runs')->where('kind', 'deliveries')->pluck('window_from')->all())
        ->toBe(['2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01']);
});

it('sums Passorde results: the first failure wins, skipped only when nothing ran', function () {
    $ok = new RunResult(PassordeKind::Deliveries, RunStatus::Succeeded, 2, 1);
    $failed = new RunResult(PassordeKind::Deliveries, RunStatus::Failed, 1, 0, 'HTTP 500 — first');
    $later = new RunResult(PassordeKind::Deliveries, RunStatus::Failed, 0, 0, 'HTTP 500 — second');
    $skipped = new RunResult(PassordeKind::Deliveries, RunStatus::Skipped);

    expect(SyncOutcome::of([$ok, $failed, $later]))
        ->status->toBe(SyncStatus::Failed)
        ->seen->toBe(3)
        ->changed->toBe(1)
        ->error->toBe('HTTP 500 — first')
        ->and(SyncOutcome::of([$ok, $ok])->status)->toBe(SyncStatus::Succeeded)
        ->and(SyncOutcome::of([$skipped])->status)->toBe(SyncStatus::Skipped);
});

it('trips on any Passorde call in file mode, before anything is sent', function () {
    fileMode();

    $api = app(EvolizApi::class);
    $provider = app(SyncProvider::class);

    expect($api)->toBeInstanceOf(ModeAwareEvolizApi::class)
        ->and($provider)->toBeInstanceOf(FileSyncProvider::class)
        ->and($provider->writesEnabled())->toBeTrue()
        ->and(fn () => $api->get('/paytypes'))->toThrow(SyncFileModeException::class)
        ->and(fn () => iterator_to_array($api->paginate('/articles')))->toThrow(SyncFileModeException::class)
        ->and(fn () => $api->send('POST', '/deliveries'))->toThrow(SyncFileModeException::class)
        ->and(fn () => $provider->sync(SyncKind::Articles))->toThrow(SyncFileModeException::class);

    Http::assertNothingSent();
});
