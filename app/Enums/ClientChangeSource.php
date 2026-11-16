<?php

namespace App\Enums;

enum ClientChangeSource: string
{
    /** A sales person's correction, sent with an order. */
    case Order = 'order';

    /** Seen by the client sync: someone changed the client in Evoliz. */
    case Evoliz = 'evoliz';

    /** An admin's edit on the client page (the optional client field). */
    case Admin = 'admin';

    /** A CSV import changed the client (file mode). */
    case Import = 'import';
}
