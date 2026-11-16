<?php

namespace App\Console\Commands;

use App\Enums\SyncKind;

class SyncArticlesCommand extends SyncCommand
{
    protected $signature = 'sync:articles
        {--queue : Dispatch the job to the queue instead of running inline}
        {--trigger=console : Recorded trigger (console, schedule)}';

    /** @var list<string> Kept for one release, until the RUNBOOK and habits move. */
    protected $aliases = ['evoliz:sync-articles'];

    protected $description = 'Import articles from Evoliz and record the run in sync_runs';

    protected function kind(): SyncKind
    {
        return SyncKind::Articles;
    }
}
