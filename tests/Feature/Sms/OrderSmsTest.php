<?php

use App\Domain\Orders\ApprovalService;
use App\Domain\Orders\OrderPresenter;
use App\Domain\Sms\FrenchMobile;
use App\Domain\Sms\OrderSms;
use App\Enums\OrderStatus;
use App\Enums\SmsStatus;
use App\Integrations\Ovh\Jobs\SendOrderSmsJob;
use App\Models\Client;
use App\Models\Order;
use App\Models\SmsLog;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

const OVH_TEXT = 'Passorde : votre commande n° C-2026-0012 (13 plants, 626,67 € TTC) est enregistrée, bon de livraison BL-20269001052. Merci !';

beforeEach(function () {
    config()->set('server.sms.enabled', true);
    config()->set('services.ovh', [
        'endpoint' => 'https://ovh.test/1.0',
        'app_key' => 'app-key',
        'app_secret' => 'app-secret',
        'consumer_key' => 'consumer-key',
        'sms_service' => 'sms-test-1',
        'sms_sender' => null,
    ]);

    $this->client = Client::factory()->create(['phone' => '06 12 34 56 78', 'mobile' => null]);
    $this->order = Order::factory()->for($this->client)->status(OrderStatus::Sent)->create([
        'number' => 'C-2026-0012',
        'total_quantity' => '13.00',
        'total_ttc' => '626.67',
        'evoliz_delivery_id' => 7000030,
        'evoliz_delivery_number' => 'BL-20269001052',
    ]);
});

/**
 * Fake OVH: a fixed server time and an accepted SMS job, or the given failure.
 *
 * @param  array{0: int, 1: array<string, string>}|null  $failure  [status, body]
 */
function fakeOvh(?array $failure = null): void
{
    Http::fake([
        'ovh.test/1.0/auth/time' => Http::response('1760430000'),
        'ovh.test/1.0/sms/*' => $failure === null
            ? Http::response(['totalCreditsRemoved' => 2, 'invalidReceivers' => [], 'ids' => [987654], 'validReceivers' => ['+33612345678']])
            : Http::response($failure[1], $failure[0]),
    ]);
}

it('sends the order SMS with a correctly signed request', function () {
    fakeOvh();

    SendOrderSmsJob::dispatchSync($this->order->id);

    Http::assertSent(function (Request $request) {
        if ($request->url() !== 'https://ovh.test/1.0/sms/sms-test-1/jobs') {
            return false;
        }

        $body = '{"receivers":["+33612345678"],"message":"'.OVH_TEXT.'","senderForResponse":true,"noStopClause":true,"charset":"UTF-8","class":"phoneDisplay"}';

        return $request->method() === 'POST'
            && $request->body() === $body
            && $request->header('X-Ovh-Application') === ['app-key']
            && $request->header('X-Ovh-Consumer') === ['consumer-key']
            && $request->header('X-Ovh-Timestamp') === ['1760430000']
            // sha1("app-secret+consumer-key+POST+<url>+<body>+1760430000"), computed outside PHP.
            && $request->header('X-Ovh-Signature') === ['$1$b305caeca36dfd3839855daeca1d3b38cfb2ca72'];
    });

    $log = SmsLog::query()->sole();
    expect($log->status)->toBe(SmsStatus::Sent)
        ->and($log->phone)->toBe('+33612345678')
        ->and($log->text)->toBe(OVH_TEXT)
        ->and($log->ovh_job_id)->toBe(987654)
        ->and($log->sent_at)->not->toBeNull();
});

it('uses the validated sender name when one is configured', function () {
    config()->set('services.ovh.sms_sender', 'MYSENDER');
    fakeOvh();

    SendOrderSmsJob::dispatchSync($this->order->id);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/jobs')
        && $request['sender'] === 'MYSENDER'
        && ! isset($request['senderForResponse']));
});

it('prefers the Evoliz mobile over a landline phone', function () {
    $this->client->update(['phone' => '09 71 23 45 67', 'mobile' => '07.11.22.33.44']);
    fakeOvh();

    SendOrderSmsJob::dispatchSync($this->order->id);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/jobs') && $request['receivers'] === ['+33711223344']);
    expect(SmsLog::query()->sole()->phone)->toBe('+33711223344');
});

it('skips clients without a French mobile and sends nothing', function (?string $phone, ?string $mobile) {
    $this->client->update(['phone' => $phone, 'mobile' => $mobile]);
    Http::fake();

    SendOrderSmsJob::dispatchSync($this->order->id);

    Http::assertNothingSent();
    $log = SmsLog::query()->sole();
    expect($log->status)->toBe(SmsStatus::Skipped)
        ->and($log->phone)->toBeNull()
        ->and($log->error)->toBe("Le client n'a pas de numéro de mobile français (06 ou 07).");
})->with([
    'landline' => ['09 74 12 34 56', null],
    'UK mobile' => ['+44 7911 123456', null],
    'Belgian mobile' => ['+32 470 12 34 56', ''],
    'no phone' => [null, null],
]);

it('logs a failure without touching the order', function () {
    fakeOvh([403, ['message' => 'Invalid signature', 'class' => 'Client::Forbidden']]);

    SendOrderSmsJob::dispatchSync($this->order->id);

    $log = SmsLog::query()->sole();
    expect($log->status)->toBe(SmsStatus::Failed)
        ->and($log->error)->toBe('HTTP 403 — Invalid signature')
        ->and($log->sent_at)->toBeNull()
        ->and($this->order->refresh()->status)->toBe(OrderStatus::Sent);
});

it('logs a number OVH refuses as a failure', function () {
    Http::fake([
        'ovh.test/1.0/auth/time' => Http::response('1760430000'),
        'ovh.test/1.0/sms/*' => Http::response(['totalCreditsRemoved' => 0, 'invalidReceivers' => ['+33612345678'], 'ids' => [], 'validReceivers' => []]),
    ]);

    SendOrderSmsJob::dispatchSync($this->order->id);

    expect(SmsLog::query()->sole()->error)->toBe('OVH a refusé le numéro +33612345678.');
});

it('sends nothing while SMS_ENABLED is false', function () {
    config()->set('server.sms.enabled', false);
    Http::fake();

    SendOrderSmsJob::dispatchSync($this->order->id);

    Http::assertNothingSent();
    expect(SmsLog::query()->count())->toBe(0)
        ->and(OrderPresenter::detail($this->order, User::factory()->admin()->create())['sms'])->toBeNull();
});

it('queues the SMS when an order becomes sent, only with the flag on', function (bool $enabled) {
    config()->set('server.sms.enabled', $enabled);
    Queue::fake();
    $order = Order::factory()->status(OrderStatus::Approved)->create();

    app(ApprovalService::class)->markSent($order, 1, 'BL-1');

    $enabled
        ? Queue::assertPushed(SendOrderSmsJob::class, fn (SendOrderSmsJob $job) => $job->orderId === $order->id && ! $job->resend)
        : Queue::assertNotPushed(SendOrderSmsJob::class);
})->with(['enabled' => [true], 'disabled' => [false]]);

it('is a unique job per order', function () {
    Queue::fake();

    SendOrderSmsJob::dispatch($this->order->id);
    SendOrderSmsJob::dispatch($this->order->id);
    SendOrderSmsJob::dispatch(Order::factory()->status(OrderStatus::Sent)->create()->id);

    Queue::assertPushed(SendOrderSmsJob::class, 2);
    expect((new SendOrderSmsJob($this->order->id))->uniqueId())->toBe('sms-order-'.$this->order->id);
});

it('sends once, and again only when the admin resends', function () {
    fakeOvh();

    SendOrderSmsJob::dispatchSync($this->order->id);
    SendOrderSmsJob::dispatchSync($this->order->id);
    expect(SmsLog::query()->count())->toBe(1);

    SendOrderSmsJob::dispatchSync($this->order->id, true);
    expect(SmsLog::query()->where('status', SmsStatus::Sent)->count())->toBe(2);
});

it('lets the admin resend through the API', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();

    actingAs($admin);
    postJson("/api/admin/orders/{$this->order->uuid}/sms")
        ->assertOk()
        ->assertJsonPath('order.sms.recipient', '06 12 34 56 78')
        ->assertJsonPath('order.sms.can_resend', true);
    Queue::assertPushed(SendOrderSmsJob::class, fn (SendOrderSmsJob $job) => $job->resend);

    $pending = Order::factory()->create();
    postJson("/api/admin/orders/{$pending->uuid}/sms")->assertConflict();

    actingAs($this->order->user);
    postJson("/api/admin/orders/{$this->order->uuid}/sms")->assertForbidden();
});

it('shows the SMS status to admins only', function () {
    fakeOvh([500, ['message' => 'Internal']]);
    SendOrderSmsJob::dispatchSync($this->order->id);

    $admin = OrderPresenter::detail($this->order, User::factory()->admin()->create());
    expect($admin['sms'])->toMatchArray([
        'recipient' => '06 12 34 56 78',
        'status' => 'failed',
        'phone' => '06 12 34 56 78',
        'error' => 'HTTP 500 — Internal',
        'can_resend' => true,
    ])->and(OrderPresenter::detail($this->order, $this->order->user)['sms'])->toBeNull();
});

it('formats the text the French way', function () {
    $this->order->update(['total_quantity' => '12.50', 'total_ttc' => '1234.5']);

    expect(OrderSms::text($this->order))->toBe('Passorde : votre commande n° C-2026-0012 (12,5 plants, 1 234,50 € TTC) est enregistrée, bon de livraison BL-20269001052. Merci !');
});

it('normalises French mobiles only', function (?string $input, ?string $expected) {
    expect(FrenchMobile::normalise($input))->toBe($expected);
})->with([
    ['0612345678', '+33612345678'],
    ['06 12 34 56 78', '+33612345678'],
    ['07.11.22.33.44', '+33711223344'],
    ['+33 6 12 34 56 78', '+33612345678'],
    ['+33 (0)6 12 34 56 78', '+33612345678'],
    ['0033612345678', '+33612345678'],
    ['33612345678', '+33612345678'],
    ['612345678', null],
    ['09 74 12 34 56', null],
    ['08 00 12 34 56', null],
    ['+32 470 12 34 56', null],
    ['06 12 34 56', null],
    ['', null],
    [null, null],
]);
