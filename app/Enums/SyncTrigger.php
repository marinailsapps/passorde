<?php

namespace App\Enums;

enum SyncTrigger: string
{
    case Schedule = 'schedule';
    case Manual = 'manual';
    case Console = 'console';
}
