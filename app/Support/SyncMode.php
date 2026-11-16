<?php

namespace App\Support;

use App\Models\Article;

/**
 * Where the catalogue, the clients and the approved orders live: in Evoliz, or in
 * the app alone, fed by CSV files and read back through exports ("file mode",
 * also called standalone). Evoliz only while it is active (EvolizState: the
 * EVOLIZ_ENABLED switch, the three keys, a usable licence); every other state
 * runs as file mode, so an expired licence leaves a fully working app.
 */
final class SyncMode
{
    public const EVOLIZ = 'evoliz';

    public const FILE = 'file';

    /**
     * @return self::EVOLIZ|self::FILE
     */
    public static function provider(): string
    {
        return EvolizState::active() ? self::EVOLIZ : self::FILE;
    }

    public static function isFile(): bool
    {
        return self::provider() === self::FILE;
    }

    /**
     * Evoliz switched off, but the database holds a catalogue synced from Evoliz:
     * most likely an Evoliz instance that lost its settings. Nothing is being sent.
     * (Enabled but not running is the licence banner's job, EvolizState::reason.)
     */
    public static function mismatch(): bool
    {
        return ! EvolizState::enabled() && Article::query()->whereNotNull('evoliz_article_id')->exists();
    }
}
