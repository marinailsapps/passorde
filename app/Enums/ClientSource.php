<?php

namespace App\Enums;

enum ClientSource: string
{
    /** Imported from Evoliz. */
    case Evoliz = 'evoliz';

    /** Created in the app; pushed to Evoliz at the first approved order. */
    case App = 'app';

    /** From a CSV import (file mode). */
    case Import = 'import';
}
