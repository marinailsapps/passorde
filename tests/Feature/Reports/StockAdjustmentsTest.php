<?php

use App\Enums\ActivityAction;
use App\Enums\StockAdjustmentKind;
use App\Integrations\Sync\Jobs\AdjustStockJob;
use App\Models\ActivityLog;
use App\Models\Article;
use App\Models\Client;
use App\Models\StockAdjustment;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

beforeEach(function () {
    travelTo('2026-10-15 10:00:00');
    $this->admin = User::factory()->admin()->create();
    $this->article = Article::factory()->create(['reference' => 'ALPHA MOTTE POMMIER']);
    $this->client = Client::factory()->create();
});

it('records broken units and gifts, logs them and queues the Evoliz stock adjustment', function () {
    Queue::fake();
    actingAs($this->admin);

    post('/admin/stock-adjustments', ['kind' => 'broken', 'article_id' => $this->article->id, 'quantity' => '10', 'happened_on' => '2026-10-14', 'note' => 'rayon 2'])
        ->assertRedirect()->assertSessionHas('success', 'Plants abîmés enregistrées.');
    post('/admin/stock-adjustments', ['kind' => 'gift', 'article_id' => $this->article->id, 'quantity' => '2.5', 'happened_on' => '2026-10-15', 'client_id' => $this->client->id])
        ->assertRedirect()->assertSessionHas('success', 'Cadeau enregistré.');

    expect(StockAdjustment::query()->orderBy('id')->get()->map->only(['kind', 'quantity', 'client_id', 'user_id'])->all())->toBe([
        ['kind' => StockAdjustmentKind::Broken, 'quantity' => '10.00', 'client_id' => null, 'user_id' => $this->admin->id],
        ['kind' => StockAdjustmentKind::Gift, 'quantity' => '2.50', 'client_id' => $this->client->id, 'user_id' => $this->admin->id],
    ])->and(ActivityLog::query()->where('action', ActivityAction::StockAdjustmentAdded)->count())->toBe(2);
    Queue::assertPushed(AdjustStockJob::class, 2);

    get('/admin/stock-adjustments?kind=gift')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Admin/StockAdjustments/Index')
        ->has('entries.data', 1)
        ->where('entries.data.0.client.company_name', $this->client->company_name)
        ->where('entries.data.0.can_delete', true));
});

it('validates the entry', function () {
    actingAs($this->admin);

    post('/admin/stock-adjustments', ['kind' => 'broken', 'article_id' => $this->article->id, 'quantity' => '0', 'happened_on' => '2026-10-15', 'client_id' => $this->client->id])
        ->assertSessionHasErrors(['quantity', 'client_id']);
    post('/admin/stock-adjustments', ['kind' => 'lost', 'article_id' => 999999, 'quantity' => '1', 'happened_on' => '2026-12-31'])
        ->assertSessionHasErrors(['kind', 'article_id', 'happened_on']);
    expect(StockAdjustment::query()->count())->toBe(0);
});

it('deletes an entry within 24 hours only, and logs it', function () {
    actingAs($this->admin);
    post('/admin/stock-adjustments', ['kind' => 'broken', 'article_id' => $this->article->id, 'quantity' => '3', 'happened_on' => '2026-10-15']);
    post('/admin/stock-adjustments', ['kind' => 'broken', 'article_id' => $this->article->id, 'quantity' => '4', 'happened_on' => '2026-10-15']);
    [$first, $second] = StockAdjustment::query()->orderBy('id')->get()->all();

    delete("/admin/stock-adjustments/{$first->id}")->assertRedirect();
    expect(StockAdjustment::query()->find($first->id))->toBeNull()
        ->and(ActivityLog::query()->where('action', ActivityAction::StockAdjustmentDeleted)->sole()->meta_json)->toMatchArray(['quantity' => '3.00', 'kind' => 'broken']);

    travel(25)->hours();
    delete("/admin/stock-adjustments/{$second->id}")->assertForbidden();
    expect(StockAdjustment::query()->find($second->id))->not->toBeNull();
});

it('is for admins only', function () {
    actingAs(User::factory()->create());

    get('/admin/stock-adjustments')->assertForbidden();
    post('/admin/stock-adjustments', ['kind' => 'broken', 'article_id' => $this->article->id, 'quantity' => '1', 'happened_on' => '2026-10-15'])->assertForbidden();
});
