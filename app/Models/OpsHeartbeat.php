<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Last sign of life of a background part ("queue", "scheduler", "backup").
 * deploy/backup.sh writes the "backup" row with psql: keep the columns in step.
 *
 * @property string $name
 * @property Carbon|null $last_ok_at
 * @property Carbon|null $last_failed_at
 * @property string|null $last_error
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $updated_at
 */
class OpsHeartbeat extends Model
{
    public const QUEUE = 'queue';

    public const SCHEDULER = 'scheduler';

    public const BACKUP = 'backup';

    public const CREATED_AT = null;

    protected $primaryKey = 'name';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['name', 'last_ok_at', 'last_failed_at', 'last_error', 'meta'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_ok_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public static function beat(string $name): void
    {
        static::query()->upsert(
            [['name' => $name, 'last_ok_at' => now(), 'updated_at' => now()]],
            ['name'],
            ['last_ok_at', 'updated_at'],
        );
    }

    /**
     * Minutes since the last success, or null if there never was one.
     */
    public static function ageInMinutes(string $name): ?float
    {
        $at = static::query()->find($name)?->last_ok_at;

        return $at === null ? null : $at->diffInSeconds(now()) / 60;
    }
}
