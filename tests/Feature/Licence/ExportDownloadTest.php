<?php

use App\Domain\Orders\ApprovalService;
use App\Domain\Orders\OrderExport;
use App\Enums\ActivityAction;
use App\Enums\OrderStatus;
use App\Integrations\Sync\Jobs\PushOrderJob;
use App\Integrations\Sync\Push\OrderPush;
use App\Models\ActivityLog;
use App\Models\Article;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Passorde\Testing\TestLicence;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

/*
| "Downloaded but never marked" (an order exported by hand during a
| lapse is never sent to Evoliz later). Taking an export file records it on
| each order; such an order is never pushed by itself, and "Envoyer à Evoliz"
| sends it only after the admin confirms they checked Evoliz. An order marked
| exported stays never sendable.
*/

beforeEach(function () {
    travelTo('2026-11-14 09:00:00');
    Queue::fake();
    $this->admin = User::factory()->admin()->create();
    $this->variety = Article::factory()->create(['stock_qty' => 10, 'is_stocked' => true]);
    $this->order = Order::factory()->for(Client::factory())->status(OrderStatus::Pending)->withLines([[$this->variety, 2]])->create();
    licenceExpired();
    app(ApprovalService::class)->approve($this->admin, $this->order);
    actingAs($this->admin);
});

function downloads(Order $order): int
{
    return ActivityLog::query()->where('order_id', $order->id)->where('action', ActivityAction::ExportDownloaded)->count();
}

it('records the download on each order of the file, and nothing else changes', function (string $format) {
    $other = Order::factory()->for(Client::factory())->status(OrderStatus::Pending)->withLines([[$this->variety, 1]])->create();
    app(ApprovalService::class)->approve($this->admin, $other);

    get("/admin/orders/export.{$format}?uuids[]={$this->order->uuid}&uuids[]={$other->uuid}")->assertOk()->streamedContent();

    expect(downloads($this->order))->toBe(1)->and(downloads($other))->toBe(1)
        ->and($this->order->fresh())->status->toBe(OrderStatus::Approved)->exported_at->toBeNull()
        ->and((float) $this->variety->fresh()->stock_qty)->toBe(10.0)
        ->and(ActivityLog::query()->where('action', ActivityAction::ExportDownloaded)->where('order_id', $this->order->id)->value('meta_json'))->toMatchArray(['format' => $format]);
})->with(['csv', 'xlsx']);

it('records nothing when the file fails while it is written', function () {
    app()->instance(OrderExport::class, new class extends OrderExport
    {
        public function writeCsv(array $rows, string $target): void
        {
            throw new RuntimeException('disk full');
        }
    });

    $level = ob_get_level();
    try {
        get("/admin/orders/export.csv?uuids[]={$this->order->uuid}")->streamedContent();
    } catch (RuntimeException) {
        // The stream's own buffer stays open after the failure.
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
    }

    expect(downloads($this->order))->toBe(0);
});

it('never pushes a downloaded order by itself after the renewal', function () {
    get("/admin/orders/export.csv?uuids[]={$this->order->uuid}")->streamedContent();
    TestLicence::install();

    // Even without its standalone stamp (a replayed job), the push leaves it alone.
    $this->order->fresh()->forceFill(['standalone_at' => null])->save();
    app(OrderPush::class)->push($this->order->id);

    expect($this->order->fresh())->status->toBe(OrderStatus::Approved)->evoliz_delivery_id->toBeNull();
    Http::assertNothingSent();
});

it('sends a downloaded, unmarked order only after a confirmation naming the download date', function () {
    get("/admin/orders/export.csv?uuids[]={$this->order->uuid}")->streamedContent();
    TestLicence::install();

    get("/orders/{$this->order->uuid}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('order.can_send_to_evoliz', true)
        ->where('order.export_downloaded_at', '2026-11-14T09:00:00+00:00'));

    postJson("/api/admin/orders/{$this->order->uuid}/send-to-evoliz")->assertStatus(409)
        ->assertJsonPath('message', "Cette commande figurait dans un fichier d'export le 14/11/2026 à 10:00 : vérifiez dans Evoliz que son BL n'a pas été saisi à la main avant de l'envoyer.");
    expect($this->order->fresh()->standalone_at)->not->toBeNull();

    postJson("/api/admin/orders/{$this->order->uuid}/send-to-evoliz", ['confirm_download' => true])->assertOk();

    expect($this->order->fresh())->standalone_at->toBeNull()->downloadUnconfirmed()->toBeFalse()
        ->and(ActivityLog::query()->where('order_id', $this->order->id)->where('action', ActivityAction::SentToEvoliz)->value('meta_json'))->toBe(['confirmed_download' => true]);
    Queue::assertPushed(PushOrderJob::class, fn (PushOrderJob $job) => $job->orderId === $this->order->id);
});

it('never sends an order marked exported, confirmation or not', function () {
    get("/admin/orders/export.csv?uuids[]={$this->order->uuid}")->streamedContent();
    postJson('/api/admin/orders/mark-exported', ['uuids' => [$this->order->uuid]])->assertOk();
    TestLicence::install();

    get("/orders/{$this->order->uuid}")->assertInertia(fn (AssertableInertia $page) => $page->where('order.can_send_to_evoliz', false));
    postJson("/api/admin/orders/{$this->order->uuid}/send-to-evoliz", ['confirm_download' => true])->assertStatus(409);
});

it('sends an order that was never in a file without asking', function () {
    TestLicence::install();

    get("/orders/{$this->order->uuid}")->assertInertia(fn (AssertableInertia $page) => $page->where('order.export_downloaded_at', null));
    postJson("/api/admin/orders/{$this->order->uuid}/send-to-evoliz")->assertOk();

    expect(ActivityLog::query()->where('order_id', $this->order->id)->where('action', ActivityAction::SentToEvoliz)->value('meta_json'))->toBeNull();
});
