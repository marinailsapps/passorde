<?php

namespace App\Integrations\Sync\Licence;

use InvalidArgumentException;

/**
 * Text that is not a licence key; the reason is one of Passorde Connect's
 * (malformed_key, legacy_format, unsupported_key_version), the French text in message().
 */
final class InvalidLicenceKey extends InvalidArgumentException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function message(): string
    {
        return match ($this->reason) {
            'legacy_format' => __('This is an old JSON licence file, not a licence key: paste the one-line key received by e-mail.'),
            'unsupported_key_version' => __('This licence key is of a newer format than this installation reads: update the application.'),
            default => __('This is not a licence key: paste the whole line received by e-mail, starting with pck1.'),
        };
    }
}
