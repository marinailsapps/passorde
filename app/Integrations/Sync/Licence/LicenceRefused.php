<?php

namespace App\Integrations\Sync\Licence;

use Passorde\Licence\LicenceStatus;
use RuntimeException;

/** A licence key that decodes but does not verify: the status says why (Passorde's French reason). */
final class LicenceRefused extends RuntimeException
{
    public function __construct(public readonly LicenceStatus $status)
    {
        parent::__construct($status->reason());
    }
}
