<?php

use App\Enums\SyncKind;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Integrations\Sync\Jobs\SyncJob;
use App\Models\Paytype;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

beforeEach(fn () => $this->admin = User::factory()->admin()->create());

it('lists the last 50 runs, newest first', function () {
    foreach (range(1, 55) as $i) {
        SyncRun::create([
            'kind' => SyncKind::Articles, 'trigger' => SyncTrigger::Schedule, 'status' => SyncStatus::Succeeded,
            'started_at' => now(), 'finished_at' => now()->addSeconds(4), 'rows_seen' => 841, 'rows_changed' => $i,
        ]);
    }
    SyncRun::create(['kind' => SyncKind::Clients, 'trigger' => SyncTrigger::Manual, 'status' => SyncStatus::Failed, 'error' => 'HTTP 500']);

    actingAs($this->admin)->get('/admin/sync')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Sync/Index')
            ->has('runs', 50)
            ->where('runs.0.error', 'HTTP 500')
            ->where('runs.1.duration', 4)
            ->where('active', false));
});

it('queues a sync of each kind', function () {
    Queue::fake();

    actingAs($this->admin)->post('/admin/sync/paytypes')->assertRedirect();

    Queue::assertPushed(SyncJob::class, fn (SyncJob $job) => $job->run->kind === SyncKind::Paytypes);
    expect(SyncRun::query()->sole()->kind)->toBe(SyncKind::Paytypes);
});

it('rejects an unknown kind', function () {
    Queue::fake();

    actingAs($this->admin)->post('/admin/sync/orders')->assertSessionHasErrors('kind');

    Queue::assertNothingPushed();
});

it('enables and disables a paytype', function () {
    $paytype = Paytype::create(['evoliz_paytype_id' => 1, 'label' => 'PayPal', 'is_enabled' => false]);

    actingAs($this->admin)->patch("/admin/paytypes/{$paytype->id}", ['is_enabled' => true])->assertRedirect();

    expect($paytype->fresh()->is_enabled)->toBeTrue();
});

it('has no paytype page of its own: the toggle stays on this page', function () {
    actingAs($this->admin)->get('/admin/paytypes')->assertNotFound();
});

it('schedules the syncs without overlap', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, "'artisan' sync:"))
        ->mapWithKeys(fn ($event) => [$event->expression.' '.str($event->command)->after("'artisan' ")->value() => $event]);

    expect($events->keys()->all())->toEqualCanonicalizing([
        '*/15 * * * * sync:articles --trigger=schedule',
        '0 * * * * sync:articles --trigger=schedule',
        '0 2 * * * sync:clients --trigger=schedule',
        '30 2 * * * sync:paytypes --trigger=schedule',
        '*/15 * * * * sync:deliveries --days=7 --trigger=schedule',
        '45 2 * * * sync:deliveries --days=60 --trigger=schedule',
    ]);

    $events->each(fn ($event) => expect($event->withoutOverlapping)->toBeTrue()
        ->and($event->timezone)->toBe('Europe/Paris'));
});

it('lets every overlap lock expire within two hours', function () {
    // A run killed with its container (a deploy) must not block its command for the default 24 hours.
    collect(app(Schedule::class)->events())->each(fn ($event) => expect($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBeLessThanOrEqual(120));
});

it('runs the scheduled syncs only when SYNC_SCHEDULE_ENABLED is true', function () {
    $clients = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'sync:clients'));

    config()->set('server.sync.schedule_enabled', false);
    expect($clients->filtersPass(app()))->toBeFalse();

    config()->set('server.sync.schedule_enabled', true);
    expect($clients->filtersPass(app()))->toBeTrue();
});
