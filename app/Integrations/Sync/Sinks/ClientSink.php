<?php

namespace App\Integrations\Sync\Sinks;

use App\Domain\Clients\ClientChanges;
use App\Enums\ClientSource;
use App\Models\Client;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Passorde\Records\ClientRecord;
use Passorde\Sinks\Contracts\ClientSink as ClientSinkContract;

/**
 * The Evoliz client directory, into clients. Each record carries its primary
 * contact (the first enabled one, favourites first). extra_field is app-only
 * and never touched; clients gone from Evoliz are kept but hidden. A client the
 * app has never linked adopts the unlinked row with its code (Adoption).
 */
final class ClientSink implements ClientSinkContract
{
    private static int $held = 0;

    /**
     * Runs a client write with this sink switched off. Passorde's verbs hand the
     * client they wrote to the sink at once, but a client the app is creating has
     * no evoliz_client_id yet (the sink would add a second row), and a correction
     * is copied by ClientChanges::apply (the sink would log it as an Evoliz
     * change). The push stores what it needs itself; the next sync catches up.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function holding(Closure $work): mixed
    {
        self::$held++;

        try {
            return $work();
        } finally {
            self::$held--;
        }
    }

    /**
     * @param  list<ClientRecord>  $records
     */
    public function upsert(array $records, CarbonImmutable $syncedAt): int
    {
        if (self::$held > 0) {
            return 0;
        }

        $ids = array_map(fn (ClientRecord $record): int => $record->id, $records);
        $existing = Client::query()->whereIn('evoliz_client_id', $ids)->get()->keyBy('evoliz_client_id');
        // Records the app has never linked may take over a client created without Evoliz (Adoption).
        $adoptable = Adoption::clients(collect($records)
            ->reject(fn (ClientRecord $record) => $existing->has($record->id))
            ->mapWithKeys(fn (ClientRecord $record) => [$record->id => EvolizText::text($record->raw['code'] ?? null)])
            ->all());
        $changed = 0;

        foreach ($records as $record) {
            /** @var Client|null $client */
            $client = $existing->get($record->id);

            if ($client === null && isset($adoptable[$record->id])) {
                // Keeps its source, uuid and optional client field; the changed fields are logged below. Re-read under
                // lock: a push may have linked that row meanwhile (Adoption::claim), then this record gets its own.
                $candidate = $adoptable[$record->id];
                $changed += DB::transaction(fn () => (int) $this->write($record, Adoption::claim($candidate, $record->id) ?? self::fresh($record->id), $syncedAt));

                continue;
            }

            $changed += (int) $this->write($record, $client ?? self::fresh($record->id), $syncedAt);
        }

        // Everything seen is confirmed as of now, without touching updated_at.
        Client::query()->whereIn('evoliz_client_id', $ids)->toBase()->update(['synced_at' => $syncedAt]);

        return $changed;
    }

    /**
     * The record's values on its row; true when that changed the row.
     */
    private function write(ClientRecord $record, Client $client, CarbonImmutable $syncedAt): bool
    {
        $client->fill(self::map($record->raw, $record->contact !== null ? self::contact($record->contact->raw) : null));

        // Only real changes move updated_at, which drives the phones' delta sync.
        if ($client->exists && ! $client->isDirty()) {
            return false;
        }

        ClientChanges::recordSync($client);
        $client->synced_at = Carbon::instance($syncedAt);
        $client->save();

        return true;
    }

    /** The row of this Evoliz id, or a new one (made by a push meanwhile: firstOrNew). */
    private static function fresh(int $evolizId): Client
    {
        return Client::query()->firstOrNew(['evoliz_client_id' => $evolizId], ['source' => ClientSource::Evoliz]);
    }

    public function deactivateMissing(array $seenIds, CarbonImmutable $syncedAt): int
    {
        return Client::query()
            ->whereNotNull('evoliz_client_id')
            ->whereNotIn('evoliz_client_id', $seenIds)
            ->where('is_active', true)
            ->update(['is_active' => false, 'updated_at' => $syncedAt]);
    }

    /**
     * @param  array<array-key, mixed>  $row  One client from GET /clients
     * @param  array{name: string|null, email: string|null}|null  $contact
     * @return array<string, mixed>
     */
    public static function map(array $row, ?array $contact): array
    {
        $address = is_array($row['address'] ?? null) ? $row['address'] : [];
        $addressLine = implode(', ', array_filter([
            EvolizText::plain($address['addr'] ?? null),
            EvolizText::plain($address['addr2'] ?? null),
            EvolizText::plain($address['addr3'] ?? null),
        ]));

        return [
            'evoliz_code' => EvolizText::text($row['code'] ?? null),
            'company_name' => EvolizText::plain($row['name'] ?? null) ?? (string) ($row['code'] ?? ''),
            'contact_name' => $contact['name'] ?? null,
            'address_line' => $addressLine === '' ? null : $addressLine,
            'postcode' => EvolizText::text($address['postcode'] ?? null),
            'town' => EvolizText::plain($address['town'] ?? null),
            'phone' => EvolizText::text($row['phone'] ?? null) ?? EvolizText::text($row['mobile'] ?? null),
            // Kept apart as well: the order SMS goes to a mobile (FrenchMobile).
            'mobile' => EvolizText::text($row['mobile'] ?? null),
            'email' => $contact['email'] ?? null,
            'is_active' => (bool) ($row['enabled'] ?? true),
        ];
    }

    /**
     * Name and email of one /contacts-clients row, as the app stores them.
     *
     * @param  array<array-key, mixed>  $row
     * @return array{name: string|null, email: string|null}
     */
    public static function contact(array $row): array
    {
        $email = EvolizText::text($row['email'] ?? null);
        $first = $row['firstname'] ?? '';
        $last = $row['lastname'] ?? '';

        return [
            'name' => EvolizText::plain(trim((is_scalar($first) ? (string) $first : '').' '.(is_scalar($last) ? (string) $last : ''))),
            'email' => $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_strtolower($email) : null,
        ];
    }
}
