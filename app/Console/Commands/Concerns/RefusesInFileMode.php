<?php

namespace App\Console\Commands\Concerns;

use App\Support\SyncMode;

trait RefusesInFileMode
{
    /** True when in file mode, after saying so: the command then exits non-zero. */
    protected function refusedInFileMode(): bool
    {
        if (! SyncMode::isFile()) {
            return false;
        }

        $this->error('File mode (Evoliz not active): this command needs Evoliz and does nothing.');

        return true;
    }
}
