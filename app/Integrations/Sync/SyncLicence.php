<?php

namespace App\Integrations\Sync;

use App\Support\EvolizState;
use DateTimeZone;
use Passorde\Licence\LicenceReason;
use Passorde\Licence\LicenceState;
use Passorde\Licence\LicenceStatus;

/**
 * The Passorde licence as the app shows and enforces it. Valid and
 * grace: everything runs (grace adds an amber bar for admins and a digest
 * section). Expired, invalid or missing: no call reaches Evoliz, syncs fail with
 * the reason, pushes fail with a French text and keep their "Réessayer".
 * Sellers never see any of it. File mode has no licence.
 */
final class SyncLicence
{
    /** The licence while Evoliz is enabled (EvolizState, memoised); null when Evoliz is off. */
    public static function status(): ?LicenceStatus
    {
        return EvolizState::licence();
    }

    /**
     * The shared prop syncLicence: dates as Y-m-d (Europe/Paris), grace_until is
     * the first day past the grace, only in grace.
     *
     * @return array{state: string, reason: string, expires_at: string|null, grace_until: string|null, renew: string|null}|null
     */
    public static function view(?LicenceStatus $status): ?array
    {
        if ($status === null) {
            return null;
        }

        // The instant the grace ends (Passorde Connect: E + 15 days at 00:00 Paris, 14 days of grace), as its Paris date.
        $until = $status->state === LicenceState::Grace ? $status->graceEndsAt?->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d') : null;

        return [
            'state' => $status->state->value,
            // Passorde Connect's text. In grace, expired and for a lifetime key whose updates ended, it already says
            // how to renew, so no renew line repeats it.
            'reason' => $status->reason(),
            'expires_at' => $status->licence?->expiresAt,
            'grace_until' => $until,
            // Only where the reason gives no way out: a key missing, malformed, for another connector, and so on.
            'renew' => $status->state === LicenceState::Invalid && $status->reasonCode !== LicenceReason::UpdatesEnded
                ? __('Paste the licence key received by e-mail in Evoliz settings.')
                : null,
        ];
    }

    /**
     * Why an order cannot be sent now, or null when the licence lets it through.
     */
    public static function orderRefusal(?LicenceStatus $status): ?string
    {
        return match (true) {
            $status === null || $status->isUsable() => null,
            $status->state === LicenceState::Expired => __('Passorde licence expired: the order is not sent to Evoliz'),
            default => __('Passorde licence invalid: the order is not sent to Evoliz'),
        };
    }

    /**
     * Why nothing is sent while Evoliz is enabled yet not running (EvolizState::reason),
     * for a write queued before it stopped; null while it runs.
     */
    public static function unavailable(): ?string
    {
        return match (EvolizState::reason()) {
            EvolizState::REASON_LICENCE => self::refusal(self::status()),
            EvolizState::REASON_CREDENTIALS => __('Evoliz keys incomplete: nothing is sent to Evoliz'),
            default => EvolizState::enabled() ? null : __('Evoliz is switched off: nothing is sent to Evoliz'),
        };
    }

    /**
     * The same for a client, a new article or a stock entry.
     */
    public static function refusal(?LicenceStatus $status): ?string
    {
        return match (true) {
            $status === null || $status->isUsable() => null,
            $status->state === LicenceState::Expired => __('Passorde licence expired: nothing is sent to Evoliz'),
            default => __('Passorde licence invalid: nothing is sent to Evoliz'),
        };
    }
}
