<?php

namespace App\Domain\Sms;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\Profile\Brand;
use App\Support\SyncMode;

/**
 * The SMS a client receives when their order reaches "sent" (in file mode, when
 * it is approved), and what the admin screens say about it.
 */
final class OrderSms
{
    public static function enabled(): bool
    {
        return (bool) config('server.sms.enabled');
    }

    /**
     * The order has reached the point where its client hears about it.
     */
    public static function canBeSent(Order $order): bool
    {
        return $order->status === (SyncMode::isFile() ? OrderStatus::Approved : OrderStatus::Sent);
    }

    /**
     * The client's French mobile (+33…): Evoliz's mobile field first, then the phone.
     */
    public static function recipient(Order $order): ?string
    {
        return FrenchMobile::first($order->client->mobile, $order->client->phone);
    }

    /**
     * The profile's text (messages.sms, or messages.sms_with_bl once Evoliz gave the BL its number), with
     * :brand (brand.short_name), :number, :quantity, :unit / :units (units.*), :ttc and :bl filled in.
     */
    public static function text(Order $order): string
    {
        $file = SyncMode::isFile();

        return strtr((string) config($file ? 'passorde.messages.sms' : 'passorde.messages.sms_with_bl'), [
            ':brand' => Brand::shortName(),
            ':number' => (string) $order->number,
            ':quantity' => self::number($order->total_quantity, 0),
            ':units' => (string) config('passorde.units.plural'),
            ':unit' => (string) config('passorde.units.singular'),
            ':ttc' => self::number($order->total_ttc, 2),
            ':bl' => $file ? '' : (string) $order->evoliz_delivery_number,
        ]);
    }

    /**
     * Admin-only block of the order detail (docs/API.md "sms"); null when SMS are off.
     *
     * @return array<string, mixed>|null
     */
    public static function present(Order $order): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        $recipient = self::recipient($order);
        $last = $order->smsLogs()->latest('id')->first();

        return [
            'recipient' => $recipient !== null ? FrenchMobile::display($recipient) : null,
            'status' => $last?->status->value,
            'phone' => $last?->phone !== null ? FrenchMobile::display($last->phone) : null,
            'error' => $last?->error,
            'sent_at' => $last?->sent_at?->toIso8601String(),
            'attempted_at' => $last?->created_at->toIso8601String(),
            'can_resend' => self::canBeSent($order) && $recipient !== null,
        ];
    }

    /**
     * French number: "13", "12,5", "1 234,56" (plain spaces keep the SMS short).
     */
    private static function number(string $value, int $minDecimals): string
    {
        $formatted = number_format((float) $value, 2, ',', ' ');

        if ($minDecimals === 0) {
            $formatted = rtrim(rtrim($formatted, '0'), ',');
        }

        return $formatted;
    }
}
