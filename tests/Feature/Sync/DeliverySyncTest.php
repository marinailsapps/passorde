<?php

use App\Console\Commands\SyncDeliveriesCommand;
use App\Enums\OrderStatus;
use App\Enums\SyncKind;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Integrations\Sync\Jobs\SyncJob;
use App\Integrations\Sync\PassordeProvider;
use App\Models\Article;
use App\Models\Client;
use App\Models\EvolizDelivery;
use App\Models\EvolizDeliveryLine;
use App\Models\EvolizUser;
use App\Models\Order;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\post;

beforeEach(function () {
    // One scripted Evoliz for the whole test; a test edits the BL page between runs.
    $this->deliveries = evolizFixture('deliveries-window');
    $this->users = evolizJson(evolizFixture('users'));
    scriptEvoliz([
        'GET /deliveries' => fn () => Http::response($this->deliveries, 200, ['X-RateLimit-Remaining' => '90']),
        'GET /users' => fn () => ($this->users)(),
    ]);
});

function runDeliverySync(string $since = '2026-09-01', string $until = '2026-09-30'): SyncRun
{
    $run = SyncRun::queue(SyncKind::Deliveries, SyncTrigger::Console);
    SyncJob::dispatchSync($run, $since, $until);

    return $run->fresh();
}

it('mirrors the window with lines, totals, invoiced state and links to clients and articles', function () {
    $client = Client::factory()->create(['evoliz_client_id' => 7000180]);
    $velours = Article::factory()->create(['evoliz_article_id' => 7000260, 'reference' => 'VELOURS TIGE POM']);

    $run = runDeliverySync();

    expect($run->status)->toBe(SyncStatus::Succeeded)
        ->and($run->rows_seen)->toBe(2)
        ->and($run->rows_changed)->toBe(2);

    $invoiced = EvolizDelivery::query()->where('document_number', 'BL-20269001027')->sole();
    expect($invoiced->only(['evoliz_delivery_id', 'client_id', 'evoliz_client_id', 'evoliz_user_id', 'status_code', 'status', 'invoiced', 'total_ht', 'total_vat', 'total_ttc', 'total_quantity']))->toBe([
        'evoliz_delivery_id' => 7000040,
        'client_id' => $client->id,
        'evoliz_client_id' => 7000180,
        'evoliz_user_id' => 50001,
        'status_code' => 20,
        'status' => 'invoice',
        'invoiced' => true,
        'total_ht' => '1914.00',
        'total_vat' => '105.27',
        'total_ttc' => '2019.27',
        'total_quantity' => '33.00',
    ])->and($invoiced->documentdate->toDateString())->toBe('2026-09-24');

    $lines = $invoiced->lines;
    expect($lines)->toHaveCount(5)
        ->and($lines->map->only(['reference', 'quantity', 'unit_price_ht', 'vat_rate', 'is_free'])->all())->toBe([
            ['reference' => 'VELOURS TIGE POM', 'quantity' => '15.00', 'unit_price_ht' => '74.00', 'vat_rate' => '5.50', 'is_free' => false],
            ['reference' => 'VELOURS TIGE POM', 'quantity' => '3.00', 'unit_price_ht' => '0.00', 'vat_rate' => '5.50', 'is_free' => true],
            ['reference' => 'VERVEINE_COG', 'quantity' => '12.00', 'unit_price_ht' => '67.00', 'vat_rate' => '5.50', 'is_free' => false],
            ['reference' => 'GANTS', 'quantity' => '1.00', 'unit_price_ht' => '0.00', 'vat_rate' => '20.00', 'is_free' => true],
            ['reference' => 'SECATEURS', 'quantity' => '2.00', 'unit_price_ht' => '0.00', 'vat_rate' => '20.00', 'is_free' => true],
        ])
        ->and($lines[0]->article_id)->toBe($velours->id)
        ->and($lines[2]->article_id)->toBeNull();

    $open = EvolizDelivery::query()->where('document_number', 'BL-20269001049')->sole();
    expect($open->invoiced)->toBeFalse()->and($open->client_id)->toBeNull();

    // The Evoliz user is recorded and named from GET /users.
    expect(EvolizUser::query()->sole()->only(['evoliz_user_id', 'label', 'user_id']))->toBe(['evoliz_user_id' => 50001, 'label' => 'PRENOM OPERATEUR', 'user_id' => null]);
});

it('asks Evoliz for the window with period=custom', function () {

    runDeliverySync('2026-09-01', '2026-09-30');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/deliveries')
        && $request['period'] === 'custom' && $request['date_min'] === '2026-09-01' && $request['date_max'] === '2026-09-30' && $request['per_page'] === 100);
});

it('changes nothing when Evoliz has not changed', function () {
    runDeliverySync();
    $lineIds = EvolizDeliveryLine::query()->orderBy('id')->pluck('id')->all();
    Carbon::setTestNow(now()->addHour());

    $run = runDeliverySync();

    expect($run->rows_changed)->toBe(0)
        ->and(EvolizDeliveryLine::query()->orderBy('id')->pluck('id')->all())->toBe($lineIds)
        ->and(EvolizDelivery::query()->where('updated_at', '>', now()->subMinute())->count())->toBe(0);
});

it('rewrites the lines of a changed BL together and keeps the others', function () {
    runDeliverySync();
    $kept = EvolizDelivery::query()->where('document_number', 'BL-20269001027')->sole()->lines->pluck('id')->all();

    $this->deliveries['data'][1]['items'][0]['quantity'] = 14;
    Carbon::setTestNow(now()->addHour());
    $run = runDeliverySync();

    $changed = EvolizDelivery::query()->where('document_number', 'BL-20269001049')->sole();
    expect($run->rows_changed)->toBe(1)
        ->and($changed->lines[0]->quantity)->toBe('14.00')
        ->and($changed->lines->pluck('updated_at')->map->toIso8601String()->unique())->toHaveCount(1)
        ->and(EvolizDelivery::query()->where('document_number', 'BL-20269001027')->sole()->lines->pluck('id')->all())->toBe($kept);
});

it('links a BL to the app order it came from', function () {
    $pushed = Order::factory()->status(OrderStatus::Sent)->create(['evoliz_delivery_id' => 7000030]);
    $numbered = Order::factory()->status(OrderStatus::Sent)->create();
    $this->deliveries['data'][0]['external_document_number'] = $numbered->number;

    runDeliverySync();

    expect(EvolizDelivery::query()->where('evoliz_delivery_id', 7000030)->value('order_id'))->toBe($pushed->id)
        ->and(EvolizDelivery::query()->where('evoliz_delivery_id', 7000040)->value('order_id'))->toBe($numbered->id);
});

it('soft-deletes a BL of the window that Evoliz no longer returns, and restores it when it comes back', function () {
    runDeliverySync();
    $outside = EvolizDelivery::query()->create([
        'evoliz_delivery_id' => 1, 'document_number' => 'BL-OLD', 'status_code' => 20, 'status' => 'invoice', 'documentdate' => '2026-08-15',
        'total_ht' => 0, 'total_vat' => 0, 'total_ttc' => 0, 'total_quantity' => 0,
    ]);

    $removed = array_pop($this->deliveries['data']);
    $run = runDeliverySync();

    expect($run->rows_changed)->toBe(1)
        ->and(EvolizDelivery::withTrashed()->where('evoliz_delivery_id', 7000030)->sole()->trashed())->toBeTrue()
        ->and($outside->fresh()->trashed())->toBeFalse();

    $this->deliveries['data'][] = $removed;
    runDeliverySync();

    expect(EvolizDelivery::query()->where('evoliz_delivery_id', 7000030)->exists())->toBeTrue();
});

it('splits a long window into calendar months, one sync run each', function () {
    $windows = SyncDeliveriesCommand::windows(Carbon::parse('2023-06-01'), Carbon::parse('2023-09-10'));

    expect(array_map(fn (array $w) => $w[0]->toDateString().' '.$w[1]->toDateString(), $windows))->toBe([
        '2023-06-01 2023-06-30', '2023-07-01 2023-07-31', '2023-08-01 2023-08-31', '2023-09-01 2023-09-10',
    ])->and(SyncDeliveriesCommand::windows(Carbon::parse('2026-08-01'), Carbon::parse('2026-10-30')))->toHaveCount(1);

    artisan('evoliz:sync-deliveries', ['--since' => '2026-07-01', '--until' => '2026-10-31'])->assertSuccessful();

    expect(SyncRun::query()->where('kind', SyncKind::Deliveries)->where('status', SyncStatus::Succeeded)->count())->toBe(4)
        ->and(EvolizDelivery::query()->count())->toBe(2);
});

it('defaults to the last 7 days and a month ahead', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-22 10:00', 'Europe/Paris'));

    expect(array_map(fn (Carbon $d) => $d->toDateString(), PassordeProvider::deliveryWindow()))->toBe(['2026-10-15', '2026-11-22']);
});

it('queues the last 60 days from the sync page', function () {
    Queue::fake();
    actingAs(User::factory()->admin()->create());

    post('/admin/sync/deliveries')->assertRedirect();

    Queue::assertPushed(SyncJob::class, fn (SyncJob $job) => $job->since === now('Europe/Paris')->subDays(60)->toDateString() && $job->until === null);
});

it('maps an Evoliz user to an app user and lets the phones fetch its BLs again', function () {
    runDeliverySync();
    $seller = User::factory()->create(['name' => 'Ysolde Dupont']);
    $admin = User::factory()->admin()->create();
    Carbon::setTestNow(now()->addHour());

    actingAs($admin)->patch('/admin/evoliz-users/'.EvolizUser::query()->sole()->id, ['user_id' => $seller->id])->assertRedirect();

    expect(EvolizUser::query()->sole()->user_id)->toBe($seller->id)
        ->and(EvolizDelivery::query()->where('updated_at', '>', now()->subMinute())->count())->toBe(2);

    actingAs($admin)->get('/admin/evoliz-users')->assertInertia(fn ($page) => $page
        ->component('Admin/EvolizUsers/Index')
        ->where('evolizUsers.0.label', 'PRENOM OPERATEUR')
        ->where('evolizUsers.0.deliveries', 2));
});

it('keeps the Evoliz users unnamed when GET /users fails', function () {
    $this->users = evolizJson(['error' => 'Forbidden'], 403);

    expect(runDeliverySync()->status)->toBe(SyncStatus::Succeeded)
        ->and(EvolizUser::query()->sole()->label)->toBeNull();
});
