<?php

namespace App\Domain\Clients;

use App\Enums\ClientChangeSource;
use App\Enums\ClientChangeStatus;
use App\Integrations\Sync\Sinks\ClientSink;
use App\Models\Client;
use App\Models\ClientChange;
use App\Models\Order;
use App\Models\User;

/**
 * A client's details corrected from an order (docs/API.md "client_changes").
 * The correction is stored with the order and only reaches the client when the
 * operator approves that order: the order push (OrderPush) sends it to Evoliz, then apply()
 * copies what Evoliz holds back into the client. Until then the client keeps
 * Evoliz's values, so the nightly sync has nothing to undo. In file mode approval
 * applies it directly (applyLocally).
 */
final class ClientChanges
{
    /** Fields a sales person may correct, in display order. */
    public const FIELDS = ['contact_name', 'address_line', 'postcode', 'town', 'phone', 'mobile', 'email', 'extra_field'];

    /** Fields the client sync records when Evoliz changed them. */
    public const SYNCED = ['company_name', 'contact_name', 'address_line', 'postcode', 'town', 'phone', 'mobile', 'email'];

    /** Fields an import or an admin may change in file mode (is_active as "1" or "0" in the diff). */
    public const LOCAL = ['evoliz_code', 'company_name', 'contact_name', 'address_line', 'postcode', 'town', 'phone', 'mobile', 'email', 'extra_field', 'is_active'];

    /**
     * The given fields that differ from the client, as {field: {from, to}}.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, array{from: string|null, to: string|null}>
     */
    public static function diff(Client $client, array $values): array
    {
        $changes = [];

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }

            $from = self::normalise($client->getAttribute($field));
            $to = self::normalise($values[$field]);

            if ($field === 'email' && $to !== null) {
                $to = mb_strtolower($to);
            }

            if ($from !== $to) {
                $changes[$field] = ['from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    /**
     * Stores the order's correction, replacing the previous one (saving the
     * same order twice gives the same row). No difference left: no row.
     *
     * @param  array<string, mixed>|null  $values  client_changes from the request
     */
    public static function saveForOrder(Order $order, User $user, ?array $values): void
    {
        $order->loadMissing('client');
        $changes = $values === null ? [] : self::diff($order->client, $values);
        $existing = ClientChange::query()->where('order_id', $order->id)->first();

        if ($changes === []) {
            $existing?->delete();

            return;
        }

        $attributes = [
            'client_id' => $order->client_id,
            // The operator saving a seller's order unchanged does not become the correction's author.
            'user_id' => $existing !== null && $existing->diff == $changes ? $existing->user_id : $user->id,
            'source' => ClientChangeSource::Order,
            'status' => ClientChangeStatus::Pending,
            'diff' => $changes,
            'decided_by' => null,
            'error' => null,
        ];

        $existing === null
            ? ClientChange::create(['order_id' => $order->id, ...$attributes])
            : $existing->update($attributes);
    }

    /**
     * The order will not be sent (deleted or rejected): its correction is dropped.
     */
    public static function cancelForOrder(Order $order, ?User $by = null): void
    {
        ClientChange::query()
            ->where('order_id', $order->id)
            ->where('status', ClientChangeStatus::Pending)
            ->update(['status' => ClientChangeStatus::Cancelled, 'decided_by' => $by?->id, 'updated_at' => now()]);
    }

    /**
     * The operator approved the order without the correction.
     */
    public static function rejectForOrder(Order $order, User $admin): bool
    {
        return (bool) ClientChange::query()
            ->where('order_id', $order->id)
            ->where('status', ClientChangeStatus::Pending)
            ->update(['status' => ClientChangeStatus::Rejected, 'decided_by' => $admin->id, 'updated_at' => now()]);
    }

    /**
     * Copies into the client what Evoliz now holds (the PATCH answers) and the
     * app-only optional client field (ClientExtraField), then marks the change applied, or failed with the
     * error when part of it could not be sent.
     *
     * @param  array<string, mixed>|null  $evolizClient  the client as Evoliz returned it after the update
     * @param  array<string, mixed>|null  $evolizContact  the contact as Evoliz returned it after the update
     */
    public static function apply(ClientChange $change, ?array $evolizClient, ?array $evolizContact, ?string $error = null): void
    {
        $client = $change->client;
        $values = $change->newValues();

        if ($evolizClient !== null) {
            $client->fill(array_intersect_key(
                ClientSink::map($evolizClient, null),
                array_flip(['address_line', 'postcode', 'town', 'phone', 'mobile']),
            ));
        }

        if ($evolizContact !== null) {
            $contact = ClientSink::contact($evolizContact);
            $client->fill(['contact_name' => $contact['name'], 'email' => $contact['email']]);
        }

        if (array_key_exists('extra_field', $values) && $error === null) {
            $client->extra_field = $values['extra_field'];
        }

        $client->save();
        $change->update([
            'status' => $error === null ? ClientChangeStatus::Applied : ClientChangeStatus::Failed,
            'applied_at' => $error === null ? now() : null,
            'error' => $error,
        ]);
    }

    /**
     * File mode: no Evoliz to ask, every new value goes straight to the client.
     */
    public static function applyLocally(ClientChange $change): void
    {
        $change->client->fill($change->newValues())->save();
        $change->update(['status' => ClientChangeStatus::Applied, 'applied_at' => now(), 'error' => null]);
    }

    /**
     * Called by the client sync before it saves a client it changed: what
     * Evoliz changed on its side goes into the history.
     */
    public static function recordSync(Client $client): void
    {
        if (! $client->exists) {
            return;
        }

        self::record($client, self::SYNCED, ClientChangeSource::Evoliz, null);
    }

    /**
     * File mode: called before saving a client an import or an admin changed,
     * so that the history stays complete. Nothing changed: no row.
     */
    public static function recordDirty(Client $client, ClientChangeSource $source, ?int $userId): void
    {
        self::record($client, self::LOCAL, $source, $userId);
    }

    /**
     * @param  list<string>  $fields
     */
    private static function record(Client $client, array $fields, ClientChangeSource $source, ?int $userId): void
    {
        $changes = [];
        foreach ($fields as $field) {
            if ($client->isDirty($field)) {
                $from = self::normalise($client->getOriginal($field));
                $to = self::normalise($client->getAttribute($field));

                if ($from !== $to) {
                    $changes[$field] = ['from' => $from, 'to' => $to];
                }
            }
        }

        if ($changes !== []) {
            ClientChange::create([
                'client_id' => $client->id,
                'user_id' => $userId,
                'source' => $source,
                'status' => ClientChangeStatus::Applied,
                'diff' => $changes,
                'applied_at' => now(),
            ]);
        }
    }

    /**
     * The change as the order pages show it.
     *
     * @return array<string, mixed>
     */
    public static function present(ClientChange $change): array
    {
        return [
            'status' => $change->status->value,
            'changes' => $change->diff,
            'error' => $change->error,
            'decided_by' => $change->decider?->name,
            'applied_at' => $change->applied_at?->toIso8601String(),
        ];
    }

    /**
     * "adresse, téléphone" for the activity log.
     *
     * @param  list<string>  $fields
     */
    public static function labels(array $fields): string
    {
        return implode(', ', array_map(fn (string $field) => mb_strtolower($field === ClientExtraField::ATTRIBUTE ? ClientExtraField::label() : __('client_field.'.$field)), $fields));
    }

    public static function normalise(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
