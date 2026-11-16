<?php

namespace App\Integrations\Sync\Licence;

use App\Models\Setting;
use App\Support\EvolizState;
use DateTimeImmutable;
use Passorde\Licence\Contracts\LicenceStore;
use Passorde\Licence\FileLicenceStore;
use Passorde\Licence\LicenceKey;

/**
 * Where the app keeps the Passorde licence. Once an admin has entered a key in
 * the settings page, the key lives in the database (settings, encrypted) and the
 * nightly refresh writes back there. Until then everything goes to the file
 * at passorde-connect.licence.path, so an installation that got its licence as a
 * file keeps it there, refreshes included.
 */
final class HostLicenceStore implements LicenceStore
{
    public const CURRENT = 'evoliz.licence';

    public const PREVIOUS = 'evoliz.licence_previous';

    public const REFRESHED_AT = 'evoliz.licence_refreshed_at';

    public function current(): ?string
    {
        return $this->inDatabase() ? Setting::read(self::CURRENT) : $this->file()->current();
    }

    public function previous(): ?string
    {
        return $this->inDatabase() ? Setting::read(self::PREVIOUS) : $this->file()->previous();
    }

    public function replace(string $raw): void
    {
        if ($this->inDatabase()) {
            $this->replaceInDatabase(LicenceKey::normalize($raw));

            return;
        }

        $this->file()->replace($raw);
        EvolizState::flush();
    }

    /**
     * The settings page: from now on the licence key lives in the database. The file, if any, is left alone.
     */
    public function replaceInDatabase(string $key, ?int $userId = null): void
    {
        $current = Setting::read(self::CURRENT);

        if ($current !== null && $current !== $key) {
            Setting::write(self::PREVIOUS, $current, $userId);
        }

        Setting::write(self::CURRENT, $key, $userId);
        EvolizState::flush();
    }

    /** The key saved by the settings page, if any (never shown in full). */
    public function storedKey(): ?string
    {
        return Setting::read(self::CURRENT);
    }

    public function lastRefreshAt(): ?DateTimeImmutable
    {
        if (! $this->inDatabase()) {
            return $this->file()->lastRefreshAt();
        }

        $at = Setting::read(self::REFRESHED_AT);

        return $at === null ? null : new DateTimeImmutable($at);
    }

    public function markRefreshed(DateTimeImmutable $at): void
    {
        if ($this->inDatabase()) {
            Setting::write(self::REFRESHED_AT, $at->format(DATE_ATOM));

            return;
        }

        $this->file()->markRefreshed($at);
    }

    public function inDatabase(): bool
    {
        return Setting::read(self::CURRENT) !== null;
    }

    /** Built per call: the path follows the configuration (tests point it at a temporary directory). */
    private function file(): FileLicenceStore
    {
        return new FileLicenceStore((string) config('passorde-connect.licence.path'));
    }
}
