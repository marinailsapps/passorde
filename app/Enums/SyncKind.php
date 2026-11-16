<?php

namespace App\Enums;

enum SyncKind: string
{
    case Clients = 'clients';
    case Articles = 'articles';
    case Paytypes = 'paytypes';
    case Deliveries = 'deliveries';
}
