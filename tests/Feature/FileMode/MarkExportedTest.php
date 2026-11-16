<?php

use App\Domain\Orders\StockConflictFinder;
use App\Enums\ActivityAction;
use App\Enums\OrderStatus;
use App\Models\ActivityLog;
use App\Models\Article;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

beforeEach(function () {
    fileMode();
    Http::preventStrayRequests();
    Queue::fake();
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
    $this->variety = Article::factory()->imported()->create(['stock_qty' => 10, 'is_stocked' => true]);
    $this->client = Client::factory()->imported()->create();
});

/**
 * @param  list<array{0: Article, 1: int|float, 2?: bool}>  $lines
 */
function approvedWith(array $lines): Order
{
    return Order::factory()->for(test()->client)->status(OrderStatus::Approved)->withLines($lines)->create();
}

it('marks the given orders only, and lowers the stock by the paid and free quantities', function () {
    $inFile = approvedWith([[$this->variety, 3], [$this->variety, 1, true]]);
    $sinceApproved = approvedWith([[$this->variety, 2]]);

    postJson('/api/admin/orders/mark-exported', ['uuids' => [$inFile->uuid]])
        ->assertOk()
        ->assertExactJson(['exported' => [$inFile->number], 'skipped' => []]);

    expect($inFile->refresh()->exported_at)->not->toBeNull()
        ->and($inFile->exported_by)->toBe($this->admin->id)
        ->and($inFile->status)->toBe(OrderStatus::Approved)
        ->and($this->variety->refresh()->stock_qty)->toBe('6.00')
        ->and($sinceApproved->refresh()->exported_at)->toBeNull();
});

it('is safe to replay: an order already exported is skipped and the stock moves once', function () {
    $order = approvedWith([[$this->variety, 3]]);

    postJson('/api/admin/orders/mark-exported', ['uuids' => [$order->uuid]])->assertOk();
    postJson('/api/admin/orders/mark-exported', ['uuids' => [$order->uuid]])
        ->assertOk()
        ->assertJsonPath('exported', [])
        ->assertJsonPath('skipped.0.number', $order->number);

    expect($this->variety->refresh()->stock_qty)->toBe('7.00');
});

it('skips orders that are not approved', function () {
    $pending = Order::factory()->for($this->client)->withLines([[$this->variety, 3]])->create();

    postJson('/api/admin/orders/mark-exported', ['uuids' => [$pending->uuid]])
        ->assertOk()
        ->assertJsonPath('exported', [])
        ->assertJsonPath('skipped.0.number', $pending->number);

    expect($pending->refresh()->exported_at)->toBeNull()->and($this->variety->refresh()->stock_qty)->toBe('10.00');
});

it('leaves an article without stock management alone', function () {
    $unmanaged = Article::factory()->imported()->create(['stock_qty' => null, 'is_stocked' => false]);
    $order = approvedWith([[$unmanaged, 3], [$this->variety, 1]]);

    postJson('/api/admin/orders/mark-exported', ['uuids' => [$order->uuid]])->assertOk();
    postJson("/api/admin/orders/{$order->uuid}/unmark-exported")->assertOk();

    expect($unmanaged->refresh()->stock_qty)->toBeNull()->and($this->variety->refresh()->stock_qty)->toBe('10.00');
});

it('ends the reservation and the stock conflict at export, and undoing brings them back', function () {
    $order = approvedWith([[$this->variety, 12]]);
    expect((float) Article::query()->withReserved()->find($this->variety->id)->reserved_qty)->toBe(12.0)
        ->and(app(StockConflictFinder::class)->all())->toHaveKey($order->id);

    postJson('/api/admin/orders/mark-exported', ['uuids' => [$order->uuid]])->assertOk();

    expect((float) Article::query()->withReserved()->find($this->variety->id)->reserved_qty)->toBe(0.0)
        ->and(app(StockConflictFinder::class)->all())->not->toHaveKey($order->id)
        ->and($this->variety->refresh()->stock_qty)->toBe('-2.00');

    postJson("/api/admin/orders/{$order->uuid}/unmark-exported")->assertOk();

    expect((float) Article::query()->withReserved()->find($this->variety->id)->reserved_qty)->toBe(12.0)
        ->and($this->variety->refresh()->stock_qty)->toBe('10.00');
});

it('moves updated_at both ways, so the author\'s phone catches the new label', function () {
    travelTo('2026-11-07 08:00');
    $order = approvedWith([[$this->variety, 1]]);
    $seller = $order->user;

    travelTo('2026-11-07 09:00');
    postJson('/api/admin/orders/mark-exported', ['uuids' => [$order->uuid]])->assertOk();
    expect($order->refresh()->updated_at->toDateTimeString())->toBe('2026-11-07 09:00:00');

    actingAs($seller);
    getJson('/api/orders?updated_since='.urlencode(Carbon::parse('2026-11-07 08:30')->toIso8601String()))
        ->assertOk()
        ->assertJsonPath('data.0.uuid', $order->uuid)
        ->assertJsonPath('data.0.status_label', __('order.label.exported'))
        ->assertJsonPath('data.0.display_status', 'approved');

    actingAs($this->admin);
    travelTo('2026-11-07 10:00');
    postJson("/api/admin/orders/{$order->uuid}/unmark-exported")
        ->assertOk()
        ->assertJsonPath('order.exported_at', null)
        ->assertJsonPath('order.exported_by', null)
        ->assertJsonPath('order.can_unmark_exported', false);
    expect($order->refresh()->updated_at->toDateTimeString())->toBe('2026-11-07 10:00:00');
});

it('logs both moves in the activity, with the stock before and after', function () {
    $order = approvedWith([[$this->variety, 3]]);

    postJson('/api/admin/orders/mark-exported', ['uuids' => [$order->uuid]])->assertOk();
    postJson("/api/admin/orders/{$order->uuid}/unmark-exported")->assertOk();

    $entries = ActivityLog::query()->where('order_id', $order->id)->orderBy('id')->get();

    expect($entries->pluck('action')->all())->toBe([ActivityAction::Exported, ActivityAction::ExportCancelled])
        ->and($entries[0]->user_id)->toBe($this->admin->id)
        ->and($entries[0]->meta_json['stock'][0])->toMatchArray(['article_id' => $this->variety->id, 'before' => '10.00', 'after' => '7.00'])
        ->and($entries[1]->meta_json['stock'][0])->toMatchArray(['before' => '7.00', 'after' => '10.00']);
});

it('refuses to undo an order that is not exported', function () {
    $order = approvedWith([[$this->variety, 3]]);

    postJson("/api/admin/orders/{$order->uuid}/unmark-exported")->assertStatus(409);

    expect($this->variety->refresh()->stock_qty)->toBe('10.00');
});

it('is for admins only', function () {
    $order = approvedWith([[$this->variety, 3]]);
    actingAs($order->user);

    postJson('/api/admin/orders/mark-exported', ['uuids' => [$order->uuid]])->assertForbidden();
    postJson("/api/admin/orders/{$order->uuid}/unmark-exported")->assertForbidden();
});

it('validates the list of uuids', function () {
    postJson('/api/admin/orders/mark-exported', ['uuids' => []])->assertUnprocessable();
    postJson('/api/admin/orders/mark-exported', ['uuids' => ['not-a-uuid']])->assertUnprocessable();
    postJson('/api/admin/orders/mark-exported', ['uuids' => array_map(fn () => fake()->uuid(), range(1, 501))])->assertUnprocessable();
});

it('filters and counts the orders to export and the exported ones', function () {
    $waiting = approvedWith([[$this->variety, 1]]);
    $done = approvedWith([[$this->variety, 1]]);
    postJson('/api/admin/orders/mark-exported', ['uuids' => [$done->uuid]])->assertOk();
    Order::factory()->for($this->client)->withLines([[$this->variety, 1]])->create();

    getJson('/api/admin/orders?status=to_export')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uuid', $waiting->uuid)
        ->assertJsonPath('counts.to_export', 1)
        ->assertJsonPath('counts.exported', 1)
        ->assertJsonPath('counts.approved', 2)
        ->assertJsonPath('counts.pending', 1);

    getJson('/api/admin/orders?status=exported')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $done->uuid);
    getJson('/api/admin/orders?status=approved')->assertOk()->assertJsonCount(2, 'data');
});

it('names the exporter and offers the undo on the order, to admins', function () {
    $order = approvedWith([[$this->variety, 1]]);

    getJson("/api/orders/{$order->uuid}")->assertJsonPath('order.can_unmark_exported', false);

    postJson('/api/admin/orders/mark-exported', ['uuids' => [$order->uuid]])->assertOk();

    getJson("/api/orders/{$order->uuid}")
        ->assertJsonPath('order.exported_by', $this->admin->name)
        ->assertJsonPath('order.can_unmark_exported', true);

    actingAs($order->user);
    getJson("/api/orders/{$order->uuid}")->assertJsonPath('order.can_unmark_exported', false);
});

it('adds the to_export badge on the home page, for admins', function () {
    approvedWith([[$this->variety, 1]]);

    get('/')->assertInertia(fn ($page) => $page->where('badges.to_export', 1));
});

it('refuses the mark, the undo and the filters in Evoliz mode, for an order that goes to Evoliz', function () {
    evolizMode();
    $order = approvedWith([[$this->variety, 1]]);

    // The routes exist in both modes, for orders approved without Evoliz; this one is refused.
    postJson('/api/admin/orders/mark-exported', ['uuids' => [$order->uuid]])->assertOk()
        ->assertJsonPath('exported', [])
        ->assertJsonPath('skipped.0.reason', __('This order goes to Evoliz: it is not marked exported.'));
    expect($order->fresh()->exported_at)->toBeNull();
    postJson("/api/admin/orders/{$order->uuid}/unmark-exported")->assertStatus(409);
    getJson('/api/admin/orders?status=to_export')->assertUnprocessable();
    getJson('/api/admin/orders')->assertJsonMissingPath('counts.to_export');
    get('/')->assertInertia(fn ($page) => $page->missing('badges.to_export'));
});
