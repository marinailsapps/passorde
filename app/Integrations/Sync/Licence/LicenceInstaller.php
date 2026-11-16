<?php

namespace App\Integrations\Sync\Licence;

use App\Support\EvolizState;
use Passorde\Licence\LicenceState;
use Passorde\Licence\LicenceStatus;

/**
 * The licence key typed in the settings page: decoded, verified, then kept in
 * the database (HostLicenceStore). An expired key is accepted (it shows its
 * dates and the app runs standalone), an invalid one never replaces the
 * current licence, as Passorde's own install does.
 */
final class LicenceInstaller
{
    /**
     * @throws InvalidLicenceKey not a key
     * @throws LicenceRefused a key that does not verify
     */
    public function install(string $typed, int $userId): LicenceStatus
    {
        $key = LicenceKeys::normalize($typed);
        $status = EvolizState::verify($key);

        if ($status->state === LicenceState::Invalid) {
            throw new LicenceRefused($status);
        }

        // Stateless: the same store the package is given (AppServiceProvider).
        app(HostLicenceStore::class)->replaceInDatabase($key, $userId);

        return $status;
    }
}
