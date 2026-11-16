<?php

namespace App\Integrations\Ovh;

use RuntimeException;

/** OVH refused or did not answer an SMS request; the message is shown to the admin. */
class OvhSmsException extends RuntimeException {}
