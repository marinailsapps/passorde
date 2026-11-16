<?php

use App\Enums\OrderStatus;
use App\Enums\SmsStatus;
use App\Enums\SyncKind;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Mail\OpsDigestMail;
use App\Models\OpsHeartbeat;
use App\Models\Order;
use App\Models\SmsLog;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\artisan;
use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

beforeEach(function () {
    travelTo('2026-10-14 05:30:00'); // 07:30 in Paris
    Mail::fake();
});

it('fails ops:check until the heartbeats are recorded, and again when they are stale', function () {
    artisan('ops:check')->assertFailed();

    // The queue is sync in tests, so the queued heartbeat runs at once.
    artisan('ops:heartbeat')->assertSuccessful();
    artisan('ops:check')->assertSuccessful();
    artisan('ops:check queue')->assertSuccessful();

    travel(11)->minutes();
    artisan('ops:check scheduler')->assertFailed()->expectsOutputToContain('scheduler: last heartbeat 11.0 min ago');
    artisan('ops:check nonsense')->assertExitCode(2);
});

it('sends no digest when all is well', function () {
    config()->set('server.ops.backups_expected', true);
    User::factory()->admin()->create(['email' => 'chef@example.com']);
    artisan('ops:heartbeat');
    OpsHeartbeat::beat(OpsHeartbeat::BACKUP);
    Order::factory()->create(); // pending since now

    artisan('ops:daily-digest')->assertSuccessful()->expectsOutput('Nothing to report; no email sent.');

    Mail::assertNothingSent();
});

it('emails the admins everything that needs attention', function () {
    config()->set('server.ops.backups_expected', true);
    config()->set('server.ops.digest_to', ['ops@example.com']);
    User::factory()->admin()->create(['email' => 'Chef@Example.com']);
    User::factory()->admin()->inactive()->create(['email' => 'former@example.com']);
    User::factory()->create(['email' => 'sales@example.com']);

    $failed = Order::factory()->status(OrderStatus::Failed)->create(['number' => 'C-2026-0042', 'evoliz_error' => 'HTTP 400 — articleid : invalid']);
    $old = Order::factory()->create(['number' => 'C-2026-0007', 'created_at' => now()->subDays(3)]);
    SyncRun::query()->create(['kind' => SyncKind::Articles, 'trigger' => SyncTrigger::Schedule, 'status' => SyncStatus::Failed, 'error' => 'Evoliz HTTP 503']);
    SyncRun::query()->create(['kind' => SyncKind::Clients, 'trigger' => SyncTrigger::Schedule, 'status' => SyncStatus::Failed, 'error' => 'old'])
        ->forceFill(['created_at' => now()->subDays(2)])->save();
    DB::table('failed_jobs')->insert([
        'uuid' => 'a5b6', 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Integrations\\Evoliz\\Jobs\\SyncClientsJob']),
        'exception' => "RuntimeException: boom\n#0 trace", 'failed_at' => now(),
    ]);
    SmsLog::query()->create(['order_id' => $failed->id, 'phone' => '+33612345678', 'text' => 'x', 'status' => SmsStatus::Failed, 'error' => 'HTTP 403 — Invalid signature']);
    OpsHeartbeat::query()->create(['name' => OpsHeartbeat::BACKUP, 'last_ok_at' => now()->subHours(30), 'last_failed_at' => now()->subHours(3), 'last_error' => 'restic: repository not found']);
    OpsHeartbeat::beat(OpsHeartbeat::SCHEDULER);

    artisan('ops:daily-digest')->assertSuccessful();

    Mail::assertSent(OpsDigestMail::class, function (OpsDigestMail $mail) {
        $text = $mail->render();

        return $mail->hasTo('chef@example.com')
            && $mail->hasTo('ops@example.com')
            && ! $mail->hasTo('former@example.com')
            && ! $mail->hasTo('sales@example.com')
            && $mail->envelope()->subject === 'Passorde : 7 points à vérifier'
            && str_contains($text, "Commandes en échec d'envoi vers Evoliz (1)")
            && str_contains($text, 'C-2026-0042 · ')
            && str_contains($text, 'HTTP 400 — articleid : invalid')
            && str_contains($text, 'Synchronisations Evoliz en échec ces dernières 24 h (1)')
            && str_contains($text, '14/10/2026 07:30 · Catalogue · Evoliz HTTP 503')
            && str_contains($text, 'Tâches de fond en échec (1)')
            && str_contains($text, 'SyncClientsJob · RuntimeException: boom')
            && str_contains($text, 'Commandes en attente depuis plus de 2 jours (1)')
            && str_contains($text, 'C-2026-0007 · ')
            && str_contains($text, 'Dernière sauvegarde réussie : 13/10/2026 01:30 (il y a plus de 26 heures).')
            && str_contains($text, 'Dernière tentative en échec le 14/10/2026 04:30 : restic: repository not found')
            && str_contains($text, "File d'attente (queue) : aucun signe de vie enregistré.")
            && ! str_contains($text, 'Planificateur')
            && str_contains($text, 'SMS aux clients en échec ces dernières 24 h (1)');
    });
});

it('reports a missing backup only where backups are configured', function () {
    User::factory()->admin()->create(['email' => 'chef@example.com']);
    artisan('ops:heartbeat');

    config()->set('server.ops.backups_expected', false);
    artisan('ops:daily-digest')->assertSuccessful();
    Mail::assertNothingSent();

    config()->set('server.ops.backups_expected', true);
    artisan('ops:daily-digest')->assertSuccessful();
    Mail::assertSent(OpsDigestMail::class, fn (OpsDigestMail $mail) => str_contains($mail->render(), 'Aucune sauvegarde réussie enregistrée.'));
});

it('fails loudly when there is something to report but nobody to tell', function () {
    Order::factory()->status(OrderStatus::Failed)->create();

    artisan('ops:daily-digest')->assertFailed();
    Mail::assertNothingSent();
});

it('schedules the heartbeat every minute and the digest at 07:30 Paris time', function () {
    $events = collect(app(Schedule::class)->events())->keyBy(fn (Event $event) => (string) preg_replace('/^.*artisan.? /', '', (string) $event->command));

    expect($events['ops:heartbeat']->expression)->toBe('* * * * *')
        ->and($events['ops:daily-digest']->expression)->toBe('30 7 * * *')
        ->and($events['ops:daily-digest']->timezone)->toBe('Europe/Paris');
});
