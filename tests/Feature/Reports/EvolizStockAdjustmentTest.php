<?php

use App\Domain\Ops\HealthReport;
use App\Domain\Reports\StockReport;
use App\Enums\ActivityAction;
use App\Enums\AttributeSource;
use App\Enums\EvolizStockStatus;
use App\Integrations\Sync\Jobs\AdjustStockJob;
use App\Integrations\Sync\Push\Subject;
use App\Integrations\Sync\SyncProvider;
use App\Models\ActivityLog;
use App\Models\Article;
use App\Models\ArticleAttribute;
use App\Models\StockAdjustment;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\post;
use function Pest\Laravel\travelTo;

beforeEach(function () {
    Sleep::fake();
    travelTo('2026-10-20 10:00:00');
    config()->set('passorde-connect.evoliz', [...config('passorde-connect.evoliz'), 'base_url' => 'https://evoliz.test', 'company_id' => 10001, 'public_key' => 'public', 'secret_key' => 'secret']);
    forgetEvolizClients();

    $this->admin = User::factory()->admin()->create();
    $this->above = Article::factory()->create(['evoliz_article_id' => 7000240, 'reference' => 'ASTRA MT POM', 'stock_qty' => 4]);
    ArticleAttribute::query()->create(['article_id' => $this->above->id, 'category' => 'POMMIER', 'variant' => 'motte', 'needs_review' => false, 'source' => AttributeSource::Auto]);
});

/** The article stock held by the fake Evoliz, and the PATCH bodies it received. */
final class FakeEvolizStock
{
    /** @var list<array<string, mixed>> */
    public array $patches = [];

    /** While true, GET answers 503: the stock cannot be read back. */
    public bool $unreadable = false;

    public function __construct(public float $stock) {}
}

/**
 * A fake Evoliz holding one article's stock: GET answers the recorded payload
 * with the current stock, PATCH stores stocked_quantity. $onPatch can change
 * the answer (an error, or a sale moving the stock right after).
 *
 * @param  array<string, mixed>  $article  overrides of the recorded GET /articles/7000240 body
 */
function fakeEvolizStock(float $stock, array $article = [], ?Closure $onPatch = null): FakeEvolizStock
{
    $state = new FakeEvolizStock($stock);

    Http::fake(function (Request $request) use ($state, $article, $onPatch) {
        if (str_ends_with($request->url(), '/api/login')) {
            return Http::response(['access_token' => 'test-token']);
        }
        expect($request->url())->toBe('https://evoliz.test/api/v1/companies/10001/articles/7000240');

        if ($request->method() === 'GET' && $state->unreadable) {
            return Http::response(['message' => 'Service Unavailable'], 503);
        }

        if ($request->method() === 'PATCH') {
            $state->patches[] = $request->data();
            if ($onPatch !== null && ($answer = $onPatch($state, $request)) !== null) {
                return $answer;
            }
            $state->stock = (float) $request->data()['stocked_quantity'];
        }

        return Http::response([...evolizFixture('article-7000240'), ...$article, 'stocked_quantity' => $state->stock]);
    });

    return $state;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function brokenEntry(Article $article, string $quantity = '1', array $attributes = []): StockAdjustment
{
    return StockAdjustment::query()->create([
        'article_id' => $article->id, 'kind' => 'broken', 'quantity' => $quantity, 'happened_on' => '2026-10-20',
        'user_id' => test()->admin->id, 'evoliz_status' => EvolizStockStatus::Pending, ...$attributes,
    ]);
}

it('deducts a broken unit from the Evoliz stock and logs GET, PATCH, GET', function () {
    $evoliz = fakeEvolizStock(4);
    actingAs($this->admin);

    post('/admin/stock-adjustments', ['kind' => 'broken', 'article_id' => $this->above->id, 'quantity' => '1', 'happened_on' => '2026-10-20'])->assertRedirect();

    $entry = StockAdjustment::query()->sole();
    expect($evoliz->patches)->toBe([['stocked_quantity' => 3]])
        ->and($entry->only(['evoliz_status', 'evoliz_before', 'evoliz_after', 'evoliz_applied', 'evoliz_error']))->toBe([
            'evoliz_status' => EvolizStockStatus::Sent, 'evoliz_before' => '4.00', 'evoliz_after' => '3.00', 'evoliz_applied' => true, 'evoliz_error' => null,
        ])
        ->and($this->above->fresh()->stock_qty)->toBe('3.00')
        ->and(passordeRequests()->map(fn (stdClass $row) => [$row->kind, $row->method, $row->path, $row->http_status, $row->status, $row->subject_type, $row->subject_id])->all())->toBe([
            ['stock.read', 'GET', '/articles/7000240', 200, 'succeeded', Subject::STOCK_ADJUSTMENT, $entry->id],
            ['stock.adjust', 'PATCH', '/articles/7000240', 200, 'succeeded', Subject::STOCK_ADJUSTMENT, $entry->id],
            ['stock.verify', 'GET', '/articles/7000240', 200, 'succeeded', Subject::STOCK_ADJUSTMENT, $entry->id],
        ]);
});

it('adds the quantity back when the entry is deleted', function () {
    $evoliz = fakeEvolizStock(4);
    actingAs($this->admin);
    post('/admin/stock-adjustments', ['kind' => 'gift', 'article_id' => $this->above->id, 'quantity' => '2', 'happened_on' => '2026-10-20']);
    $entry = StockAdjustment::query()->sole();

    delete("/admin/stock-adjustments/{$entry->id}")->assertRedirect();

    $entry = StockAdjustment::withTrashed()->sole();
    expect($evoliz->patches)->toBe([['stocked_quantity' => 2], ['stocked_quantity' => 4]])
        ->and($entry->trashed())->toBeTrue()
        ->and($entry->only(['evoliz_status', 'evoliz_before', 'evoliz_after', 'evoliz_applied']))->toBe([
            'evoliz_status' => EvolizStockStatus::Sent, 'evoliz_before' => '2.00', 'evoliz_after' => '4.00', 'evoliz_applied' => false,
        ])
        ->and($this->above->fresh()->stock_qty)->toBe('4.00');
});

it('sends nothing in test mode and keeps the entry for later', function () {
    config()->set('passorde-connect.writes_enabled', false);
    Http::fake();
    actingAs($this->admin);

    post('/admin/stock-adjustments', ['kind' => 'broken', 'article_id' => $this->above->id, 'quantity' => '1', 'happened_on' => '2026-10-20']);

    Http::assertNothingSent();
    expect(StockAdjustment::query()->sole()->only(['evoliz_status', 'evoliz_error', 'evoliz_applied']))->toBe([
        'evoliz_status' => EvolizStockStatus::Disabled, 'evoliz_error' => 'Envoi Evoliz désactivé (mode test)', 'evoliz_applied' => false,
    ]);
});

it('skips an article without stock management in Evoliz', function () {
    $evoliz = fakeEvolizStock(0, ['stock_management' => false]);

    (new AdjustStockJob(brokenEntry($this->above)->id))->handle(app(SyncProvider::class));

    expect($evoliz->patches)->toBe([])
        ->and(StockAdjustment::query()->sole()->only(['evoliz_status', 'evoliz_error', 'evoliz_applied']))->toBe([
            'evoliz_status' => EvolizStockStatus::Skipped, 'evoliz_error' => 'Article sans gestion de stock dans Evoliz : rien à ajuster.', 'evoliz_applied' => false,
        ]);
});

it('marks a refused PATCH failed, and the retry applies it once', function () {
    /** @var bool $refuse */
    $refuse = true;
    $evoliz = fakeEvolizStock(4, onPatch: function () use (&$refuse) {
        return $refuse ? Http::response(['error' => 'Bad request', 'message' => ['stocked_quantity' => ['Invalid value.']]], 400) : null;
    });
    actingAs($this->admin);
    post('/admin/stock-adjustments', ['kind' => 'broken', 'article_id' => $this->above->id, 'quantity' => '1', 'happened_on' => '2026-10-20']);
    $entry = StockAdjustment::query()->sole();

    expect($entry->only(['evoliz_status', 'evoliz_error', 'evoliz_applied']))->toBe([
        'evoliz_status' => EvolizStockStatus::Failed, 'evoliz_error' => 'HTTP 400 — stocked_quantity : Invalid value.', 'evoliz_applied' => false,
    ]);

    $refuse = false;
    post("/admin/stock-adjustments/{$entry->id}/retry")->assertRedirect()->assertSessionHas('success', 'Renvoyé à Evoliz.');

    expect($evoliz->stock)->toBe(3.0)
        ->and($entry->fresh()->evoliz_status)->toBe(EvolizStockStatus::Sent)
        ->and(ActivityLog::query()->where('action', ActivityAction::StockAdjustmentRetried)->count())->toBe(1);
});

it('recognises a timed-out PATCH that had gone through by reading the stock back', function () {
    $evoliz = fakeEvolizStock(4, onPatch: function (FakeEvolizStock $state, Request $request) {
        $state->stock = (float) $request->data()['stocked_quantity'];

        return Http::response(['error' => 'Gateway timeout'], 504);
    });
    $entry = brokenEntry($this->above);

    app(SyncProvider::class)->reconcileStock($entry);

    expect($evoliz->patches)->toHaveCount(1)
        ->and($evoliz->stock)->toBe(3.0)
        ->and($entry->fresh()->only(['evoliz_status', 'evoliz_applied', 'evoliz_before', 'evoliz_after']))->toBe([
            'evoliz_status' => EvolizStockStatus::Sent, 'evoliz_applied' => true, 'evoliz_before' => '4.00', 'evoliz_after' => '3.00',
        ]);
});

it('does not apply twice when a timed-out PATCH had gone through and the stock could not be read back', function () {
    $first = true;
    $evoliz = fakeEvolizStock(4, onPatch: function (FakeEvolizStock $state, Request $request) use (&$first) {
        if ($first) {
            $first = false;
            $state->stock = (float) $request->data()['stocked_quantity'];
            $state->unreadable = true;

            return Http::response(['error' => 'Gateway timeout'], 504);
        }

        return null;
    });
    $entry = brokenEntry($this->above);
    $provider = app(SyncProvider::class);

    $provider->reconcileStock($entry);
    expect($entry->fresh()->only(['evoliz_status', 'evoliz_before', 'evoliz_after']))->toBe([
        'evoliz_status' => EvolizStockStatus::Failed, 'evoliz_before' => '4.00', 'evoliz_after' => '3.00', // what was read and written
    ]);

    // Evoliz answers again: the stock shows the value written, so nothing is sent (StockExpectation).
    $evoliz->unreadable = false;
    $provider->reconcileStock($entry);
    expect($evoliz->patches)->toHaveCount(1)
        ->and($evoliz->stock)->toBe(3.0)
        ->and($entry->fresh()->only(['evoliz_status', 'evoliz_applied', 'evoliz_before', 'evoliz_after', 'evoliz_error']))->toBe([
            'evoliz_status' => EvolizStockStatus::Sent, 'evoliz_applied' => true, 'evoliz_before' => '4.00', 'evoliz_after' => '3.00', 'evoliz_error' => null,
        ])
        ->and(passordeRequests(Subject::STOCK_ADJUSTMENT, $entry->id)->where('method', 'PATCH')->count())->toBe(1);
});

it('sends the PATCH again when the timed-out one had not gone through', function () {
    $first = true;
    $evoliz = fakeEvolizStock(4, onPatch: function (FakeEvolizStock $state) use (&$first) {
        if ($first) {
            $first = false;
            $state->unreadable = true;

            return Http::response(['error' => 'Gateway timeout'], 504);
        }

        return null;
    });
    $entry = brokenEntry($this->above);
    $provider = app(SyncProvider::class);

    $provider->reconcileStock($entry);
    expect($entry->fresh()->evoliz_status)->toBe(EvolizStockStatus::Failed);

    $evoliz->unreadable = false;
    $provider->reconcileStock($entry);
    expect($evoliz->patches)->toBe([['stocked_quantity' => 3], ['stocked_quantity' => 3]])
        ->and($evoliz->stock)->toBe(3.0)
        ->and($entry->fresh()->evoliz_status)->toBe(EvolizStockStatus::Sent);
});

it('warns when something else moved the stock during the adjustment', function () {
    $evoliz = fakeEvolizStock(4, onPatch: function (FakeEvolizStock $state) {
        $state->stock = 1.0; // our 3, then a BL of 2 right after

        return Http::response([...evolizFixture('article-7000240'), 'stocked_quantity' => 3]);
    });
    $entry = brokenEntry($this->above);

    app(SyncProvider::class)->reconcileStock($entry);

    expect($entry->fresh()->only(['evoliz_status', 'evoliz_after', 'evoliz_error']))->toBe([
        'evoliz_status' => EvolizStockStatus::Sent, 'evoliz_after' => '1.00',
        'evoliz_error' => 'Stock Evoliz après l’ajustement : 1 (attendu 3). Un autre mouvement de stock a eu lieu en même temps : vérifiez dans Evoliz.',
    ])
        ->and(ActivityLog::query()->where('action', ActivityAction::EvolizStockMismatch)->sole()->meta_json)->toMatchArray(['expected' => 3, 'actual' => 1])
        ->and($this->above->fresh()->stock_qty)->toBe('1.00');
});

it('queues one job per entry; a deletion while it waits is handled by that job, and one landing after a deduction adds back', function () {
    Queue::fake();
    actingAs($this->admin);
    post('/admin/stock-adjustments', ['kind' => 'broken', 'article_id' => $this->above->id, 'quantity' => '1', 'happened_on' => '2026-10-20']);
    $entry = StockAdjustment::query()->sole();

    // Still queued: no second job, the queued one sees the deletion and does nothing.
    delete("/admin/stock-adjustments/{$entry->id}");
    Queue::assertPushed(AdjustStockJob::class, 1);
    $evoliz = fakeEvolizStock(4);
    app(SyncProvider::class)->reconcileStock($entry);
    expect($evoliz->patches)->toBe([]);

    // The deduction had already landed when the entry was deleted: the job adds back.
    $entry = StockAdjustment::withTrashed()->sole();
    $entry->update(['evoliz_applied' => true, 'evoliz_status' => EvolizStockStatus::Sent]);
    $evoliz->stock = 3.0;
    app(SyncProvider::class)->reconcileStock($entry);

    expect($evoliz->stock)->toBe(4.0)->and($entry->fresh()->evoliz_applied)->toBeFalse();
});

it('subtracts in the report only what Evoliz does not have yet', function () {
    $this->above->update(['stock_qty' => 10]);
    brokenEntry($this->above, '1', ['evoliz_status' => EvolizStockStatus::Sent, 'evoliz_applied' => true]);   // already in Evoliz's 10
    brokenEntry($this->above, '2', ['evoliz_status' => EvolizStockStatus::Disabled]);                         // test mode: not in Evoliz
    brokenEntry($this->above, '3', ['evoliz_status' => EvolizStockStatus::Failed]);                           // failed: not in Evoliz
    $gift = StockAdjustment::query()->create(['article_id' => $this->above->id, 'kind' => 'gift', 'quantity' => '4', 'happened_on' => '2026-10-20', 'user_id' => $this->admin->id, 'evoliz_status' => EvolizStockStatus::Sent, 'evoliz_applied' => true]);
    $gift->delete(); // deleted, Evoliz not given back yet: +4

    $report = app(StockReport::class)->build(...StockReport::period(null, null));
    $tabs = array_column($report['tabs'], null, 'key');
    /** @var list<array<string, mixed>> $rows */
    $rows = $tabs['pommier']['rows'];
    /** @var list<array<string, mixed>> $broken */
    $broken = $tabs['broken']['rows'];
    $row = array_column($rows, null, 'reference')['ASTRA MT POM'];

    expect($row)->toMatchArray(['stock' => '10.00', 'broken' => '-5.00', 'gifts' => '4.00', 'at_term' => '9.00'])
        ->and(array_column($broken, 'evoliz_status'))->toEqualCanonicalizing(['sent', 'disabled', 'failed']);
});

it('reports failed adjustments in the digest and sends the test-mode ones once writes are on', function () {
    brokenEntry($this->above, '1', ['evoliz_status' => EvolizStockStatus::Failed, 'evoliz_error' => 'HTTP 500 — boom']);
    brokenEntry($this->above, '2', ['evoliz_status' => EvolizStockStatus::Disabled]);
    brokenEntry($this->above, '3', ['evoliz_status' => EvolizStockStatus::Skipped]);

    $section = collect(app(HealthReport::class)->sections())->firstWhere('title', 'Plants abîmés / cadeaux non reportés dans le stock Evoliz (1)');
    expect($section['lines'] ?? null)->toBe(['20/10/2026 · ASTRA MT POM × 1 · HTTP 500 — boom']);

    $evoliz = fakeEvolizStock(10);
    Pest\Laravel\artisan('evoliz:push-stock-adjustments')->expectsOutputToContain('2 queued.')->assertSuccessful();

    expect($evoliz->stock)->toBe(7.0)
        ->and(StockAdjustment::query()->where('evoliz_applied', true)->count())->toBe(2);
});

it('recognises an uncertain PATCH logged in the read-only evoliz_jobs', function () {
    $evoliz = fakeEvolizStock(3); // the logged PATCH 4 → 3 went through, its answer was lost
    $entry = brokenEntry($this->above);
    $entry->update(['evoliz_status' => EvolizStockStatus::Failed, 'evoliz_before' => 4, 'evoliz_after' => 3]);
    DB::table('evoliz_jobs')->insert([
        'stock_adjustment_id' => $entry->id, 'kind' => 'adjust_stock', 'method' => 'PATCH', 'path' => '/articles/7000240',
        'status' => 'failed', 'http_status' => null, 'attempts' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    app(SyncProvider::class)->reconcileStock($entry);

    expect($evoliz->patches)->toBe([])
        ->and($entry->fresh()->only(['evoliz_status', 'evoliz_applied']))->toBe(['evoliz_status' => EvolizStockStatus::Sent, 'evoliz_applied' => true]);
});
