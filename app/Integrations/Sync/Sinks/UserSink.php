<?php

namespace App\Integrations\Sync\Sinks;

use App\Models\EvolizUser;
use Carbon\CarbonImmutable;
use Passorde\Records\UserRecord;
use Passorde\Sinks\Contracts\UserSink as UserSinkContract;

/**
 * Names the Evoliz users seen on BLs (DeliverySink creates their rows). Only
 * an unnamed row gets a label; nothing else is created or changed.
 */
final class UserSink implements UserSinkContract
{
    /**
     * @param  list<UserRecord>  $records
     */
    public function upsert(array $records, CarbonImmutable $syncedAt): int
    {
        $named = 0;

        foreach ($records as $record) {
            $first = $record->raw['firstname'] ?? '';
            $last = $record->raw['lastname'] ?? '';
            $label = EvolizText::text(trim((is_scalar($first) ? (string) $first : '').' '.(is_scalar($last) ? (string) $last : '')))
                ?? EvolizText::text($record->raw['email'] ?? null);

            $named += EvolizUser::query()->where('evoliz_user_id', $record->id)->whereNull('label')->update(['label' => $label]);
        }

        return $named;
    }

    public function deactivateMissing(array $seenIds, CarbonImmutable $syncedAt): int
    {
        return 0;
    }
}
