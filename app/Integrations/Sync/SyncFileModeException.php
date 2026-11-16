<?php

namespace App\Integrations\Sync;

use LogicException;

/**
 * Something tried to reach the invoicing software in file mode: a branch was
 * missed. A tripwire, never caught to carry on.
 */
class SyncFileModeException extends LogicException {}
