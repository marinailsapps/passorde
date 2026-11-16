<?php

namespace App\Domain\Clients;

use App\Enums\ClientSource;
use App\Models\Client;
use App\Models\User;

/**
 * Clients typed by a user, in an order or on their own, found again by the
 * uuid generated on the phone, so that a replay never makes a second client.
 */
final class TypedClients
{
    /**
     * @param  array<string, mixed>  $fields  uuid, company_name, contact_name, address_line, postcode, town, phone, email, extra_field
     */
    public static function upsert(User $user, array $fields): Client
    {
        $attributes = [
            'company_name' => trim((string) $fields['company_name']),
            'contact_name' => $fields['contact_name'] ?? null,
            'address_line' => $fields['address_line'] ?? null,
            'postcode' => $fields['postcode'] ?? null,
            'town' => $fields['town'] ?? null,
            'phone' => $fields['phone'] ?? null,
            'email' => $fields['email'] ?? null,
            'extra_field' => $fields['extra_field'] ?? null,
        ];

        $client = Client::query()->where('uuid', $fields['uuid'])->first();

        if ($client === null) {
            return Client::create([
                ...$attributes,
                'uuid' => $fields['uuid'],
                'source' => ClientSource::App,
                'is_active' => true,
                'created_by' => $user->id,
            ]);
        }

        if ($client->isOpenTypedClient() && $client->created_by === $user->id) {
            // The author may still correct a client they typed, until it is settled (pushed to Evoliz, or in file mode its first order is decided).
            $client->update($attributes);
        }

        return $client;
    }
}
