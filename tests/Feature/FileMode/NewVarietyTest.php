<?php

use App\Domain\Orders\ApprovalService;
use App\Enums\AttributeSource;
use App\Enums\OrderStatus;
use App\Integrations\Sync\Jobs\PushArticleJob;
use App\Models\Article;
use App\Models\ArticleRequest;
use App\Models\Client;
use App\Models\Order;
use App\Models\Paytype;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

beforeEach(function () {
    fileMode();
    Http::preventStrayRequests();
    Queue::fake();
    actingAs($this->seller = User::factory()->create());
    $this->uuid = (string) Str::uuid();
    $this->fields = ['name' => 'astra', 'category' => 'POMMIER', 'variant' => 'motte', 'unit_price_ht' => 32.5];
});

it('answers created at once, with the article, and queues nothing', function () {
    putJson("/api/article-requests/{$this->uuid}", $this->fields)
        ->assertCreated()
        ->assertJsonPath('request.status', 'created')
        ->assertJsonPath('request.error', null)
        ->assertJsonPath('request.article.reference', 'ASTRA MT POM')
        ->assertJsonPath('request.article.designation', 'ASTRA MOTTE POMMIER');

    $article = Article::query()->sole();
    expect($article->evoliz_article_id)->toBeNull()
        ->and($article->unit_price_ht)->toBe('32.50')
        ->and($article->vat_rate)->toBe('5.50')
        ->and($article->is_stocked)->toBeTrue()
        ->and($article->stock_qty)->toBe('0.00')
        ->and($article->is_active)->toBeTrue()
        ->and($article->attribute->source)->toBe(AttributeSource::Manual)
        ->and($article->attribute->category)->toBe('POMMIER')
        ->and($article->attribute->variant)->toBe('motte');

    expect(ArticleRequest::query()->sole()->article_id)->toBe($article->id);
    Queue::assertNotPushed(PushArticleJob::class);
    Http::assertNothingSent();
});

it('gives the same answer when asked again', function () {
    putJson("/api/article-requests/{$this->uuid}", $this->fields)->assertCreated();

    putJson("/api/article-requests/{$this->uuid}", $this->fields)->assertOk()->assertJsonPath('request.status', 'created');
    getJson("/api/article-requests/{$this->uuid}")->assertOk()->assertJsonPath('request.article.reference', 'ASTRA MT POM');

    expect(Article::query()->count())->toBe(1);
});

it('finds the reference again, in any case, keeping its price, stock and attributes', function () {
    $existing = Article::factory()->imported()->create(['reference' => 'Astra MT Pom', 'unit_price_ht' => 40, 'stock_qty' => 12, 'is_stocked' => true, 'is_active' => false]);

    putJson("/api/article-requests/{$this->uuid}", $this->fields)
        ->assertCreated()
        ->assertJsonPath('request.status', 'created')
        ->assertJsonPath('request.article.id', $existing->id);

    expect(Article::query()->count())->toBe(1);
    $existing->refresh();
    expect($existing->unit_price_ht)->toBe('40.00')
        ->and($existing->stock_qty)->toBe('12.00')
        ->and($existing->is_active)->toBeTrue();
});

it('is usable at once in an order, and its lines catch up with the designation at approval', function () {
    $article = Article::query()->findOrFail(
        putJson("/api/article-requests/{$this->uuid}", $this->fields)->assertCreated()->json('request.article.id'),
    );
    $client = Client::factory()->imported()->create();
    $paytype = Paytype::query()->create(['label' => 'Virement', 'is_enabled' => true]);

    $orderUuid = (string) Str::uuid();
    putJson("/api/orders/{$orderUuid}", [
        'client_id' => $client->id,
        'paytype_id' => $paytype->id,
        'lines' => [['article_id' => $article->id, 'quantity' => 2, 'unit_price_ht' => 32.5, 'is_free' => false]],
    ])->assertSuccessful();

    $order = Order::query()->where('uuid', $orderUuid)->firstOrFail();
    expect($order->lines()->sole()->designation)->toBe('ASTRA MOTTE POMMIER');

    $article->update(['designation' => 'ASTRA MOTTE POMMIER PG M106 LOT 4']);
    $approved = app(ApprovalService::class)->approve(User::factory()->admin()->create(), $order);

    expect($approved->status)->toBe(OrderStatus::Approved)
        ->and($order->lines()->sole()->designation)->toBe('ASTRA MOTTE POMMIER PG M106 LOT 4');
    Http::assertNothingSent();
});

it('still queues the push in Evoliz mode', function () {
    evolizMode();

    putJson("/api/article-requests/{$this->uuid}", $this->fields)->assertCreated()->assertJsonPath('request.status', 'pending');

    Queue::assertPushed(PushArticleJob::class);
    expect(Article::query()->count())->toBe(0);
});
