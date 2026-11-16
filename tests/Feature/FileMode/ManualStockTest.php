<?php

use App\Domain\Catalog\LocalStock;
use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\patch;

beforeEach(function () {
    fileMode();
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create();
    $this->article = Article::factory()->imported()->create(['reference' => 'ALPHA', 'designation' => 'OLD', 'unit_price_ht' => 10, 'stock_qty' => 7, 'is_stocked' => true, 'is_active' => true]);
    $this->fields = ['designation' => 'ALPHA PG M106 LOT 4', 'unit_price_ht' => '12.50', 'stock_qty' => '-3.5', 'is_stocked' => true, 'is_active' => false];
});

it('lets an admin edit price, designation, stock and active, and logs it', function () {
    Carbon::setTestNow(now()->addHour());
    actingAs($this->admin);
    patch("/admin/catalog/{$this->article->id}", $this->fields)->assertRedirect()->assertSessionHas('success');

    $article = $this->article->refresh();
    expect($article->designation)->toBe('ALPHA PG M106 LOT 4')
        ->and($article->unit_price_ht)->toBe('12.50')
        ->and($article->stock_qty)->toBe('-3.50')
        ->and($article->is_active)->toBeFalse()
        ->and($article->updated_at->timestamp)->toBe(now()->timestamp);

    $entry = ActivityLog::query()->sole();
    expect($entry->action)->toBe(ActivityAction::ArticleEdited)
        ->and($entry->meta_json['reference'])->toBe('ALPHA')
        ->and($entry->meta_json['fields'])->toEqualCanonicalizing(['designation', 'unit_price_ht', 'stock_qty', 'is_active']);
    Carbon::setTestNow();
});

it('does not touch the article or log when nothing changes', function () {
    Carbon::setTestNow(now()->addHour());
    $before = $this->article->updated_at->toIso8601String();
    actingAs($this->admin);

    patch("/admin/catalog/{$this->article->id}", ['designation' => 'OLD', 'unit_price_ht' => '10', 'stock_qty' => '7.00', 'is_stocked' => true, 'is_active' => true])->assertRedirect();

    expect($this->article->refresh()->updated_at->toIso8601String())->toBe($before)
        ->and(ActivityLog::query()->count())->toBe(0);
    Carbon::setTestNow();
});

it('refuses sellers', function () {
    actingAs(User::factory()->create());

    patch("/admin/catalog/{$this->article->id}", $this->fields)->assertForbidden();

    expect($this->article->refresh()->designation)->toBe('OLD');
});

it('refuses the edit in Evoliz mode', function () {
    evolizMode();
    actingAs($this->admin);

    patch("/admin/catalog/{$this->article->id}", $this->fields)->assertForbidden();

    expect($this->article->refresh()->designation)->toBe('OLD');
});

it('validates the values', function (array $bad, string $field) {
    actingAs($this->admin);

    patch("/admin/catalog/{$this->article->id}", [...$this->fields, ...$bad])->assertSessionHasErrors($field);
})->with([
    [['unit_price_ht' => '-1'], 'unit_price_ht'],
    [['unit_price_ht' => 'x'], 'unit_price_ht'],
    [['unit_price_ht' => '1.234'], 'unit_price_ht'],
    [['stock_qty' => 'x'], 'stock_qty'],
    [['designation' => ''], 'designation'],
]);

it('moves the stock by a delta, under a lock, and says what it was', function () {
    expect(LocalStock::apply($this->article->id, -2.5))->toBe(['before' => '7.00', 'after' => '4.50'])
        ->and(LocalStock::apply($this->article->id, '0.1'))->toBe(['before' => '4.50', 'after' => '4.60'])
        ->and(LocalStock::apply($this->article->id, -10))->toBe(['before' => '4.60', 'after' => '-5.40'])
        ->and($this->article->refresh()->stock_qty)->toBe('-5.40');
});

it('starts from zero when a stocked article has no figure yet', function () {
    $this->article->update(['stock_qty' => null]);

    expect(LocalStock::apply($this->article->id, -1))->toBe(['before' => '0.00', 'after' => '-1.00']);
});

it('moves nothing for an article with no stock management', function () {
    $this->article->update(['is_stocked' => false]);

    expect(LocalStock::apply($this->article->id, -1))->toBeNull()
        ->and($this->article->refresh()->stock_qty)->toBe('7.00');
});
