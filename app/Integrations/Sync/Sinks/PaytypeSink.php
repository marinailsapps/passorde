<?php

namespace App\Integrations\Sync\Sinks;

use App\Models\Paytype;
use Carbon\CarbonImmutable;
use Passorde\Records\PaytypeRecord;
use Passorde\Sinks\Contracts\PaytypeSink as PaytypeSinkContract;

/**
 * Paytype labels. is_enabled is the admin's choice and is never changed here;
 * paytypes new to the app start disabled. None is ever deactivated.
 */
final class PaytypeSink implements PaytypeSinkContract
{
    /**
     * @param  list<PaytypeRecord>  $records
     */
    public function upsert(array $records, CarbonImmutable $syncedAt): int
    {
        $changed = 0;

        foreach ($records as $record) {
            $label = EvolizText::text($record->raw['label'] ?? null);

            if ($label === null) {
                continue;
            }

            $paytype = Paytype::query()->firstOrNew(['evoliz_paytype_id' => $record->id], ['is_enabled' => false]);
            $paytype->label = $label;

            if (! $paytype->exists || $paytype->isDirty()) {
                $changed++;
                $paytype->save();
            }
        }

        return $changed;
    }

    public function deactivateMissing(array $seenIds, CarbonImmutable $syncedAt): int
    {
        return 0;
    }
}
