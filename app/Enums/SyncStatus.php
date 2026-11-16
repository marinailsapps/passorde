<?php

namespace App\Enums;

enum SyncStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** Another run of the same kind held the lock. */
    case Skipped = 'skipped';

    public function isFinished(): bool
    {
        return ! in_array($this, [self::Queued, self::Running], true);
    }
}
