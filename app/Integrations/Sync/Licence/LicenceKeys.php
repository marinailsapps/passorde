<?php

namespace App\Integrations\Sync\Licence;

use Passorde\Licence\LicenceKey;
use Passorde\Licence\LicenceReason;

/**
 * The licence key an admin pastes in the settings page: "pck1." + base64url of
 * the signed licence (licence-key-format.md v1). Passorde Connect decodes and
 * verifies it (Passorde\Licence\LicenceKey, LicenceVerifier); here only the
 * text is checked to be a key, so that the page can say what is wrong before
 * the signature is looked at. The database keeps the key, whitespace removed.
 */
final class LicenceKeys
{
    /**
     * The key as stored, or InvalidLicenceKey with Passorde Connect's reason
     * (malformed_key, legacy_format, unsupported_key_version).
     */
    public static function normalize(string $typed): string
    {
        $decoded = LicenceKey::decode($typed);

        if ($decoded instanceof LicenceReason) {
            throw new InvalidLicenceKey($decoded->value);
        }

        return LicenceKey::normalize($typed);
    }
}
