<?php

namespace App\Enums;

enum ClientChangeStatus: string
{
    /** Saved with an order that is not sent yet. */
    case Pending = 'pending';

    /** In Evoliz and in the app. */
    case Applied = 'applied';

    /** The operator approved the order without the change. */
    case Rejected = 'rejected';

    /** Evoliz refused it; the order was sent with the old details. */
    case Failed = 'failed';

    /** Its order was deleted or rejected before approval. */
    case Cancelled = 'cancelled';
}
