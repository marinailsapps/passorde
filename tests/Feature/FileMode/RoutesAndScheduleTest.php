<?php

use App\Enums\OrderStatus;
use App\Integrations\Sync\ModeAwareEvolizApi;
use App\Integrations\Sync\SyncFileModeException;
use App\Models\Client;
use App\Models\Order;
use App\Models\Paytype;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Passorde\Api\Contracts\EvolizApi;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\call;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
});

$evolizRoutes = [
    ['POST', '/admin/clients/sync'],
    ['POST', '/admin/clients/{client}/push'],
    ['POST', '/admin/clients/{client}/changes/1/retry'],
    ['POST', '/admin/catalog/sync'],
    ['POST', '/admin/stock-adjustments/1/retry'],
    ['GET', '/admin/evoliz-users'],
    ['PATCH', '/admin/evoliz-users/1'],
    ['GET', '/admin/sync'],
    ['POST', '/admin/sync/clients'],
    // Not /api/admin/orders/{order}/retry: standalone, it settles a failed order for the export.
];

it('answers 404 on every Evoliz route in file mode', function (string $method, string $uri) {
    fileMode();
    actingAs(User::factory()->admin()->create());
    $uri = str_replace(['{client}', '{order}'], [(string) Client::factory()->imported()->create()->id, Order::factory()->create(['status' => OrderStatus::Failed])->uuid], $uri);

    call($method, $uri)->assertNotFound();
    Queue::assertNothingPushed();
})->with($evolizRoutes);

it('leaves those routes alone in Evoliz mode', function () {
    actingAs(User::factory()->admin()->create());

    get('/admin/sync')->assertOk();
    get('/admin/evoliz-users')->assertOk();
});

it('moves the paytype toggle to a page of its own', function () {
    fileMode();
    actingAs(User::factory()->admin()->create());
    $paytype = Paytype::query()->create(['label' => 'Virement', 'is_enabled' => false]);

    get('/admin/paytypes')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Admin/Paytypes/Index')
        ->where('paytypes.0.label', 'Virement')
        ->where('paytypes.0.is_enabled', false));

    patch("/admin/paytypes/{$paytype->id}", ['is_enabled' => true])->assertRedirect();

    expect($paytype->fresh()->is_enabled)->toBeTrue();
});

it('keeps the paytype page for admins', function () {
    fileMode();
    actingAs(User::factory()->create());

    get('/admin/paytypes')->assertForbidden();
});

/**
 * The commands of the schedule whose filters pass right now.
 *
 * @return list<string>
 */
function dueEvolizCommands(): array
{
    return collect(app(Schedule::class)->events())
        ->filter(fn ($event) => preg_match("/'artisan' (sync|passorde):/", (string) $event->command) === 1 && $event->filtersPass(app()))
        ->map(fn ($event) => (string) $event->command)
        ->values()->all();
}

it('schedules no Evoliz sync in file mode, even with the schedule switched on', function () {
    config()->set('server.sync.schedule_enabled', true);
    expect(dueEvolizCommands())->not->toBeEmpty();

    fileMode();
    expect(dueEvolizCommands())->toBeEmpty();
});

it('keeps the operations schedule in file mode', function () {
    fileMode();

    $commands = collect(app(Schedule::class)->events())->filter(fn ($event) => $event->filtersPass(app()))->map(fn ($event) => (string) $event->command)->implode(' ');

    expect($commands)->toContain('ops:heartbeat')->toContain('ops:daily-digest');
});

it('refuses the Evoliz commands in file mode', function (string $command) {
    fileMode();

    artisan($command)->assertFailed()->expectsOutputToContain('File mode');

    Http::assertNothingSent();
    Queue::assertNothingPushed();
    expect(DB::table('sync_runs')->count())->toBe(0);
})->with([
    'evoliz:sync-articles',
    'evoliz:sync-clients',
    'evoliz:sync-paytypes',
    'evoliz:sync-deliveries',
    'evoliz:sync-articles --queue',
    'sync:articles',
    'sync:clients',
    'sync:paytypes',
    'sync:deliveries',
    'sync:deliveries --queue',
    'evoliz:push-stock-adjustments',
]);

it('trips on any call to Evoliz in file mode, before anything is sent', function (string $call) {
    fileMode();
    $api = app(EvolizApi::class);

    // The container's transport asks the mode at each call (ModeAwareEvolizApi).
    expect($api)->toBeInstanceOf(ModeAwareEvolizApi::class);
    expect(fn () => match ($call) {
        'get' => $api->get('/paytypes'),
        'paginate' => iterator_to_array($api->paginate('/clients')),
        default => $api->send('POST', '/deliveries', ['json' => []]),
    })->toThrow(SyncFileModeException::class);

    Http::assertNothingSent();
})->with(['get', 'paginate', 'post']);

it('shows no sync bar and offers no push or retry on the client and catalogue pages', function () {
    fileMode();
    actingAs(User::factory()->admin()->create());
    $client = Client::factory()->fromApp()->create();

    get('/admin/clients')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('sync', null));
    get("/admin/clients/{$client->id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('client.can_push', false));
    get('/catalog')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('sync', null));
});
