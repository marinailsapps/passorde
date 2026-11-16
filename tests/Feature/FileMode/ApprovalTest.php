<?php

use App\Domain\Orders\OrderPresenter;
use App\Enums\ActivityAction;
use App\Enums\ClientChangeSource;
use App\Enums\ClientChangeStatus;
use App\Enums\OrderStatus;
use App\Enums\SmsStatus;
use App\Integrations\Ovh\Jobs\SendOrderSmsJob;
use App\Integrations\Sync\Jobs\PushOrderJob;
use App\Models\ActivityLog;
use App\Models\Article;
use App\Models\ArticleRequest;
use App\Models\Client;
use App\Models\ClientChange;
use App\Models\Order;
use App\Models\SmsLog;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    fileMode();
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
    $this->article = Article::factory()->imported()->create(['stock_qty' => 10]);
    $this->client = Client::factory()->imported()->create(['address_line' => 'Ancienne adresse', 'phone' => '06 12 34 56 78']);
    $this->order = Order::factory()->for($this->client)->withLines([[$this->article, 2]])->create(['total_quantity' => '2.00', 'total_ttc' => '112.45', 'number' => 'C-2026-0012']);
});

/**
 * @param  array<string, array{from: string|null, to: string|null}>  $diff
 */
function correction(Order $order, array $diff): ClientChange
{
    return ClientChange::query()->create([
        'client_id' => $order->client_id,
        'order_id' => $order->id,
        'user_id' => $order->user_id,
        'source' => ClientChangeSource::Order,
        'status' => ClientChangeStatus::Pending,
        'diff' => $diff,
    ]);
}

it('approves with no job, no HTTP call and no Evoliz wording', function () {
    Queue::fake();

    $response = postJson("/api/admin/orders/{$this->order->uuid}/approve")->assertOk();

    Queue::assertNotPushed(PushOrderJob::class);
    Queue::assertNothingPushed();
    Http::assertNothingSent();

    expect($this->order->fresh())
        ->status->toBe(OrderStatus::Approved)
        ->approved_by->toBe($this->admin->id)
        ->evoliz_delivery_id->toBeNull();

    $response->assertJsonPath('order.status', 'approved')
        ->assertJsonPath('order.status_label', 'Validée')
        ->assertJsonPath('order.exported_at', null)
        ->assertJsonPath('order.can_retry', false);
});

it('stays approved through the real queue: nothing is left to fail', function () {
    // phpunit.xml runs the queue inline: any push would hit the tripwire here.
    postJson("/api/admin/orders/{$this->order->uuid}/approve")->assertOk();

    expect($this->order->fresh()->status)->toBe(OrderStatus::Approved)
        ->and($this->order->fresh()->evoliz_error)->toBeNull();
    Http::assertNothingSent();
});

it('approves in bulk the same way', function () {
    Queue::fake();
    $other = Order::factory()->for($this->client)->withLines([[$this->article, 1]])->create();

    postJson('/api/admin/orders/approve', ['uuids' => [$this->order->uuid, $other->uuid]])
        ->assertOk()->assertJsonCount(2, 'approved');

    Queue::assertNothingPushed();
    expect($other->fresh()->status)->toBe(OrderStatus::Approved);
});

it('labels an approved order "Validée", an exported one "Exportée", for its author too', function () {
    $this->order->update(['status' => OrderStatus::Approved]);
    $author = $this->order->user;

    expect(OrderPresenter::statusLabel($this->order, $this->admin))->toBe('Validée')
        ->and(OrderPresenter::statusLabel($this->order, $author))->toBe('Validée');

    $this->order->forceFill(['exported_at' => now()])->save();

    expect(OrderPresenter::statusLabel($this->order, $this->admin))->toBe('Exportée')
        ->and(OrderPresenter::statusLabel($this->order, $author))->toBe('Exportée')
        ->and(OrderPresenter::detail($this->order, $this->admin)['exported_at'])->not->toBeNull()
        ->and(OrderPresenter::summary($this->order, $this->admin)['exported_at'])->not->toBeNull();
});

it('keeps the Evoliz wording and pushes in Evoliz mode', function () {
    evolizMode();
    Queue::fake();

    $response = postJson("/api/admin/orders/{$this->order->uuid}/approve")->assertOk();

    Queue::assertPushed(PushOrderJob::class);
    $response->assertJsonPath('order.status_label', 'Validée · envoi en cours');
});

it('applies the client correction locally at approval', function () {
    Queue::fake();
    $change = correction($this->order, [
        'address_line' => ['from' => 'Ancienne adresse', 'to' => '2 place Exemple'],
        'email' => ['from' => null, 'to' => 'sarl@exemple.test'],
        'extra_field' => ['from' => null, 'to' => 'NP-58321'],
    ]);

    postJson("/api/admin/orders/{$this->order->uuid}/approve")
        ->assertOk()
        ->assertJsonPath('order.client.address_line', '2 place Exemple')
        ->assertJsonPath('order.client_change.status', 'applied');

    expect($this->client->fresh())
        ->address_line->toBe('2 place Exemple')
        ->email->toBe('sarl@exemple.test')
        ->extra_field->toBe('NP-58321')
        ->and($change->fresh())
        ->status->toBe(ClientChangeStatus::Applied)
        ->decided_by->toBe($this->admin->id)
        ->applied_at->not->toBeNull()
        ->and(ActivityLog::query()->where('order_id', $this->order->id)->where('action', ActivityAction::ClientChangeApplied)->exists())->toBeTrue();

    Http::assertNothingSent();
});

it('leaves the correction out when the operator says so', function () {
    Queue::fake();
    $change = correction($this->order, ['address_line' => ['from' => 'Ancienne adresse', 'to' => '2 place Exemple']]);

    postJson("/api/admin/orders/{$this->order->uuid}/approve", ['apply_client_change' => false])->assertOk();

    expect($this->client->fresh()->address_line)->toBe('Ancienne adresse')
        ->and($change->fresh()->status)->toBe(ClientChangeStatus::Rejected);
});

it('refreshes a new variety\'s lines from the catalogue, and only those', function () {
    Queue::fake();
    $variety = Article::factory()->imported()->create(['designation' => 'NOUVEAU POM']);
    ArticleRequest::query()->create([
        'uuid' => (string) Str::uuid(), 'user_id' => $this->order->user_id, 'name' => 'NOUVEAU', 'category' => 'POMMIER', 'variant' => 'nu',
        'unit_price_ht' => 30, 'reference' => $variety->reference, 'designation' => 'NOUVEAU POM', 'status' => ArticleRequest::CREATED, 'article_id' => $variety->id,
    ]);
    $order = Order::factory()->for($this->client)->withLines([[$variety, 1], [$this->article, 1]])->create();
    $variety->update(['designation' => 'NOUVEAU POMMIER / CAL : 10-12']);
    $this->article->update(['designation' => 'Changed in the catalogue']);
    $before = $order->lines()->where('article_id', $this->article->id)->value('designation');

    postJson("/api/admin/orders/{$order->uuid}/approve")->assertOk();

    expect($order->lines()->where('article_id', $variety->id)->value('designation'))->toBe('NOUVEAU POMMIER / CAL : 10-12')
        ->and($order->lines()->where('article_id', $this->article->id)->value('designation'))->toBe($before);
});

it('queues the SMS at approval when SMS are on', function () {
    Queue::fake();
    config()->set('server.sms.enabled', true);

    postJson("/api/admin/orders/{$this->order->uuid}/approve")->assertOk();

    Queue::assertPushed(SendOrderSmsJob::class, fn (SendOrderSmsJob $job) => $job->orderId === $this->order->id);
    Queue::assertNotPushed(PushOrderJob::class);
});

it('queues no SMS when SMS are off', function () {
    Queue::fake();

    postJson("/api/admin/orders/{$this->order->uuid}/approve")->assertOk();

    Queue::assertNotPushed(SendOrderSmsJob::class);
});

it('sends the SMS without a delivery note, and lets the admin send it again', function () {
    config()->set('server.sms.enabled', true);
    config()->set('services.ovh', [
        'endpoint' => 'https://ovh.test/1.0', 'app_key' => 'k', 'app_secret' => 's', 'consumer_key' => 'c', 'sms_service' => 'sms-test-1', 'sms_sender' => null,
    ]);
    Http::fake([
        'ovh.test/1.0/auth/time' => Http::response('1760430000'),
        'ovh.test/1.0/sms/*' => Http::response(['totalCreditsRemoved' => 2, 'invalidReceivers' => [], 'ids' => [987654], 'validReceivers' => ['+33612345678']]),
    ]);
    $this->order->update(['status' => OrderStatus::Approved]);
    $this->order->forceFill(['total_quantity' => '13.00', 'total_ttc' => '626.67'])->save();

    SendOrderSmsJob::dispatchSync($this->order->id);

    expect(SmsLog::query()->sole())
        ->status->toBe(SmsStatus::Sent)
        ->text->toBe('Passorde : votre commande n° C-2026-0012 (13 plants, 626,67 € TTC) est enregistrée. Merci !');

    getJson("/api/orders/{$this->order->uuid}")->assertJsonPath('order.sms.can_resend', true);

    Queue::fake();
    postJson("/api/admin/orders/{$this->order->uuid}/sms")->assertOk();
    Queue::assertPushed(SendOrderSmsJob::class, fn (SendOrderSmsJob $job) => $job->resend);
});
