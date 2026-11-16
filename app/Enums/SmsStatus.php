<?php

namespace App\Enums;

enum SmsStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';

    /** The client has no French mobile: nothing was sent. */
    case Skipped = 'skipped';
}
