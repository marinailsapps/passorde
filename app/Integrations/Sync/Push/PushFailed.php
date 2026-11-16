<?php

namespace App\Integrations\Sync\Push;

use RuntimeException;

/**
 * A push step that cannot go on; the message is the French text shown to the operator.
 */
final class PushFailed extends RuntimeException {}
