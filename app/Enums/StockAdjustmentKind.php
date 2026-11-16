<?php

namespace App\Enums;

enum StockAdjustmentKind: string
{
    case Broken = 'broken';
    case Gift = 'gift';
}
