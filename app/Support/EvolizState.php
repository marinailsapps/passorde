<?php

namespace App\Support;

use InvalidArgumentException;
use Passorde\Licence\Contracts\LicenceStore;
use Passorde\Licence\LicenceReason;
use Passorde\Licence\LicenceStatus;
use Passorde\Licence\LicenceVerifier;

/**
 * Whether Evoliz runs (docs/RUNBOOK.md "EVOLIZ_ENABLED and the Evoliz settings"). Evoliz is
 * active when the developer switched it on, the three Evoliz keys are there and
 * the Passorde Connect licence is usable for the Evoliz connector (valid or in
 * its grace; Passorde Connect checks that the key lists "evoliz"). In every other state
 * the app runs standalone, exactly as in file mode (SyncMode::provider): export,
 * import and catalogue edits, local approval, no sync, no push, no Evoliz call.
 *
 * EVOLIZ_ENABLED is the one switch (true/false, 1/0, on/off, yes/no; anything else
 * throws). Unset or empty means off: Evoliz keys, a licence or any other setting
 * never switch Evoliz on by themselves.
 */
final class EvolizState
{
    /** Enabled, but a key is missing. */
    public const REASON_CREDENTIALS = 'credentials';

    /** Enabled, but the licence is expired, invalid, missing or does not cover Evoliz. */
    public const REASON_LICENCE = 'licence';

    /** The connector this app uses, as a licence's "connectors" list names it (Passorde Connect checks it). */
    public const CONNECTOR = 'evoliz';

    /** @var array{key: string, status: LicenceStatus}|null */
    private static ?array $licenceMemo = null;

    public static function enabled(): bool
    {
        $flag = config('server.evoliz_enabled');

        if (is_bool($flag)) {
            return $flag;
        }

        if (is_string($flag) && trim($flag) !== '') {
            // A typo must not change where the orders go.
            throw new InvalidArgumentException("EVOLIZ_ENABLED must be true or false, got \"{$flag}\".");
        }

        return false;
    }

    public static function credentialsComplete(): bool
    {
        return (int) config('passorde-connect.evoliz.company_id') !== 0
            && filled(config('passorde-connect.evoliz.public_key'))
            && filled(config('passorde-connect.evoliz.secret_key'));
    }

    /**
     * The licence as the app reads it; null while Evoliz is not enabled. One
     * read and one signature check per request or job (and per minute in a
     * long-running process), whatever the number of calls: writes through the
     * store and every queued job start afresh (flush).
     */
    public static function licence(): ?LicenceStatus
    {
        if (! self::enabled()) {
            return null;
        }

        $verifier = app(LicenceVerifier::class);
        $key = spl_object_id($verifier).'|'.intdiv(now()->getTimestamp(), 60);

        if (self::$licenceMemo !== null && self::$licenceMemo['key'] === $key) {
            return self::$licenceMemo['status'];
        }

        $raw = app(LicenceStore::class)->current();
        $status = $raw === null || trim($raw) === '' ? LicenceStatus::invalid(LicenceReason::NotInstalled) : $verifier->verify($raw, connector: self::CONNECTOR);
        self::$licenceMemo = ['key' => $key, 'status' => $status];

        return $status;
    }

    /**
     * A licence before it is installed (settings page): the package's verdict and the connector rule.
     */
    public static function verify(string $raw): LicenceStatus
    {
        return app(LicenceVerifier::class)->verify($raw, connector: self::CONNECTOR);
    }

    public static function active(): bool
    {
        return self::enabled() && self::credentialsComplete() && (self::licence()?->isUsable() ?? false);
    }

    /**
     * Why Evoliz is enabled yet not running; null when it runs or is off.
     *
     * @return self::REASON_*|null
     */
    public static function reason(): ?string
    {
        return match (true) {
            ! self::enabled() => null,
            ! self::credentialsComplete() => self::REASON_CREDENTIALS,
            ! (self::licence()?->isUsable() ?? false) => self::REASON_LICENCE,
            default => null,
        };
    }

    /** After the licence or the keys change, and before each queued job. */
    public static function flush(): void
    {
        self::$licenceMemo = null;
    }
}
