<?php

namespace App\Enums;

/**
 * Where a broken entry / gift outside an order stands with the Evoliz stock
 * (AdjustStockJob). The last operation: the deduction, or the add-back
 * after the entry was deleted.
 */
enum EvolizStockStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    /** Test mode (PASSORDE_WRITES_ENABLED=false): nothing was sent. */
    case Disabled = 'disabled';
    /** The article has no stock management in Evoliz: nothing to adjust. */
    case Skipped = 'skipped';
}
