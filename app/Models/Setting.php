<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * One value entered in the app, encrypted with APP_KEY (rotating APP_KEY makes
 * them unreadable: enter them again). A row that no longer decrypts reads as
 * unset, so boot, artisan and migrate carry on; unreadable() names it for the
 * settings page and the digest. Never logged, never sent back in full.
 * Read through Setting::read (memoised for the request or job; flush() after a
 * write elsewhere, done for each queued job).
 *
 * @property string $key
 * @property string|null $value
 * @property int|null $updated_by
 * @property Carbon|null $updated_at
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by'];

    /** @var array<string, Setting>|null */
    private static ?array $memo = null;

    /** @var list<string> Keys whose value no longer decrypts, as of the last read. */
    private static array $unreadable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }

    public static function read(string $key): ?string
    {
        return self::rows()[$key]->value ?? null;
    }

    public static function row(string $key): ?self
    {
        return self::rows()[$key] ?? null;
    }

    /**
     * An empty value removes the setting.
     */
    public static function write(string $key, ?string $value, ?int $userId = null): void
    {
        if ($value === null || $value === '') {
            self::query()->whereKey($key)->delete();
        } else {
            self::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
        }

        self::flush();
    }

    public static function flush(): void
    {
        self::$memo = null;
        self::$unreadable = [];
    }

    /**
     * Keys saved here whose value can no longer be decrypted (APP_KEY changed): they read as unset.
     *
     * @return list<string>
     */
    public static function unreadable(): array
    {
        self::rows();

        return self::$unreadable;
    }

    /**
     * Every readable row, once per request or job. Before the migration has run (a
     * fresh install, migrate itself) there is nothing to read.
     *
     * @return array<string, Setting>
     */
    private static function rows(): array
    {
        if (self::$memo === null) {
            try {
                $rows = self::query()->orderBy('key')->get();
            } catch (QueryException) {
                return [];
            }

            self::$memo = [];
            self::$unreadable = [];

            foreach ($rows as $row) {
                if (self::decrypts($row)) {
                    self::$memo[$row->key] = $row;
                } else {
                    self::$unreadable[] = $row->key;
                }
            }
        }

        return self::$memo;
    }

    /** Whether the stored value decrypts with the current APP_KEY (what the encrypted cast does on read). */
    private static function decrypts(self $row): bool
    {
        $raw = $row->getRawOriginal('value');

        try {
            if (is_string($raw)) {
                Crypt::decryptString($raw);
            }

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
