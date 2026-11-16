<?php

namespace App\Enums;

/** Where an article's category and variant come from; "manual" is never overwritten by a sync. */
enum AttributeSource: string
{
    case Auto = 'auto';
    case File = 'file';
    case Manual = 'manual';
}
