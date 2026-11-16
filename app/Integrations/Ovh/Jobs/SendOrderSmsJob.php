<?php

namespace App\Integrations\Ovh\Jobs;

use App\Domain\Sms\OrderSms;
use App\Enums\SmsStatus;
use App\Integrations\Ovh\OvhSmsClient;
use App\Integrations\Ovh\OvhSmsException;
use App\Models\Order;
use App\Models\SmsLog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tells the client by SMS that their order is registered (SMS_ENABLED).
 *
 * Unique per order and sent once: a second run does nothing unless the admin
 * pressed "Renvoyer" ($resend). Every attempt is written to sms_log; a failure
 * is logged there and never touches the order.
 */
class SendOrderSmsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(public int $orderId, public bool $resend = false) {}

    public function uniqueId(): string
    {
        return 'sms-order-'.$this->orderId;
    }

    public function handle(OvhSmsClient $ovh): void
    {
        $order = Order::query()->with('client')->find($this->orderId);

        if (! OrderSms::enabled() || $order === null || ! OrderSms::canBeSent($order)) {
            return;
        }

        $alreadyDone = $order->smsLogs()->whereIn('status', [SmsStatus::Sent->value, SmsStatus::Skipped->value])->exists();
        if ($alreadyDone && ! $this->resend) {
            return;
        }

        $text = OrderSms::text($order);
        $phone = OrderSms::recipient($order);

        if ($phone === null) {
            SmsLog::query()->create([
                'order_id' => $order->id,
                'text' => $text,
                'status' => SmsStatus::Skipped,
                'error' => __('The client has no French mobile number (06 or 07).'),
            ]);

            return;
        }

        $log = SmsLog::query()->create([
            'order_id' => $order->id,
            'phone' => $phone,
            'text' => $text,
            'status' => SmsStatus::Pending,
        ]);

        try {
            $jobId = $ovh->send($phone, $text);
            $log->update(['status' => SmsStatus::Sent, 'ovh_job_id' => $jobId, 'sent_at' => now()]);
        } catch (OvhSmsException $e) {
            $log->update(['status' => SmsStatus::Failed, 'error' => Str::limit($e->getMessage(), 500)]);
        } catch (Throwable $e) {
            report($e);
            $log->update(['status' => SmsStatus::Failed, 'error' => Str::limit($e->getMessage(), 500)]);
        }
    }

    /**
     * The worker gave up (timeout, crash): never leave the attempt "pending".
     */
    public function failed(?Throwable $exception): void
    {
        SmsLog::query()
            ->where('order_id', $this->orderId)
            ->where('status', SmsStatus::Pending->value)
            ->update(['status' => SmsStatus::Failed->value, 'error' => Str::limit($exception?->getMessage() ?? '?', 500)]);
    }
}
