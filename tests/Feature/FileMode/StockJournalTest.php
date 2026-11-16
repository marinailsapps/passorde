<?php

use App\Domain\Reports\StockReport;
use App\Integrations\Sync\Jobs\AdjustStockJob;
use App\Models\Article;
use App\Models\ArticleAttribute;
use App\Models\StockAdjustment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    fileMode();
    Http::preventStrayRequests();
    Queue::fake();
    actingAs($this->admin = User::factory()->admin()->create());
    $this->article = Article::factory()->imported()->create(['reference' => 'ALPHA POM', 'designation' => 'ALPHA POMMIER', 'stock_qty' => 10, 'is_stocked' => true]);
    $this->entry = fn (array $overrides = []) => post('/admin/stock-adjustments', [
        'kind' => 'broken', 'article_id' => $this->article->id, 'quantity' => '2', 'happened_on' => now('Europe/Paris')->toDateString(), ...$overrides,
    ])->assertRedirect()->assertSessionHasNoErrors();
});

it('lowers the local stock at once, with no job and no Evoliz', function () {
    ($this->entry)();

    $entry = StockAdjustment::query()->sole();
    expect($this->article->refresh()->stock_qty)->toBe('8.00')
        ->and($entry->evoliz_applied)->toBeTrue()
        ->and($entry->evoliz_status)->toBeNull()
        ->and($entry->evoliz_before)->toBe('10.00')
        ->and($entry->evoliz_after)->toBe('8.00');
    Queue::assertNotPushed(AdjustStockJob::class);
    Http::assertNothingSent();
});

it('counts gifts the same way', function () {
    ($this->entry)(['kind' => 'gift', 'quantity' => '1.5']);

    expect($this->article->refresh()->stock_qty)->toBe('8.50');
});

it('gives the quantity back on delete, once, and hides the entry', function () {
    ($this->entry)();
    $entry = StockAdjustment::query()->sole();

    delete("/admin/stock-adjustments/{$entry->id}")->assertRedirect();

    expect($this->article->refresh()->stock_qty)->toBe('10.00');
    $trashed = StockAdjustment::withTrashed()->sole();
    expect($trashed->trashed())->toBeTrue()->and($trashed->evoliz_applied)->toBeFalse();

    delete("/admin/stock-adjustments/{$entry->id}")->assertNotFound();
    expect($this->article->refresh()->stock_qty)->toBe('10.00');

    get('/admin/stock-adjustments')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('entries.data', 0));
    Queue::assertNotPushed(AdjustStockJob::class);
});

it('keeps the 24 hour window', function () {
    ($this->entry)();
    $entry = StockAdjustment::query()->sole();
    Carbon::setTestNow(now()->addHours(25));

    delete("/admin/stock-adjustments/{$entry->id}")->assertForbidden();
    Carbon::setTestNow();

    expect($this->article->refresh()->stock_qty)->toBe('8.00');
});

it('lists the entry with the local figures and no retry', function () {
    ($this->entry)();

    get('/admin/stock-adjustments')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('entries.data.0.evoliz_status', null)
        ->where('entries.data.0.evoliz_before', '10.00')
        ->where('entries.data.0.evoliz_after', '8.00')
        ->where('entries.data.0.can_retry', false)
        ->where('entries.data.0.can_delete', true));
});

it('moves nothing for an article with no stock management, and offers no retry', function () {
    $this->article->update(['is_stocked' => false, 'stock_qty' => null]);

    ($this->entry)();

    $entry = StockAdjustment::query()->sole();
    expect($this->article->refresh()->stock_qty)->toBeNull()
        ->and($entry->evoliz_applied)->toBeFalse()
        ->and($entry->evoliz_before)->toBeNull();

    get('/admin/stock-adjustments')->assertInertia(fn (AssertableInertia $page) => $page->where('entries.data.0.can_retry', false));

    delete("/admin/stock-adjustments/{$entry->id}")->assertRedirect();
    expect($this->article->refresh()->stock_qty)->toBeNull();
});

it('keeps the stock report right: an applied entry is not counted twice', function () {
    ArticleAttribute::query()->create(['article_id' => $this->article->id, 'category' => 'POMMIER', 'variant' => 'nu', 'source' => 'manual']);
    $row = function () {
        $report = app(StockReport::class)->build(now('Europe/Paris')->startOfMonth(), now('Europe/Paris')->endOfMonth());

        return collect($report['tabs'])->pluck('rows')->flatten(1)->firstWhere('article_id', $this->article->id);
    };

    ($this->entry)();
    expect((float) $row()['at_term'])->toBe(8.0);

    expect((float) $row()['broken'])->toBe(0.0);

    delete('/admin/stock-adjustments/'.StockAdjustment::query()->sole()->id);
    expect((float) $row()['at_term'])->toBe(10.0);

    $this->article->update(['is_stocked' => false]);
    ($this->entry)();
    expect($row()['at_term'])->toBeNull();
});
