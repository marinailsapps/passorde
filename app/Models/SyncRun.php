<?php

namespace App\Models;

use App\Enums\SyncKind;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property SyncKind $kind
 * @property SyncTrigger $trigger
 * @property SyncStatus $status
 * @property int|null $user_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int $rows_seen
 * @property int $rows_changed
 * @property string|null $error
 * @property Carbon $created_at
 */
class SyncRun extends Model
{
    protected $fillable = [
        'kind',
        'trigger',
        'status',
        'user_id',
        'started_at',
        'finished_at',
        'rows_seen',
        'rows_changed',
        'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => SyncKind::class,
            'trigger' => SyncTrigger::class,
            'status' => SyncStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function queue(SyncKind $kind, SyncTrigger $trigger, ?User $user = null): self
    {
        return static::create([
            'kind' => $kind,
            'trigger' => $trigger,
            'status' => SyncStatus::Queued,
            'user_id' => $user?->id,
        ]);
    }

    public static function lastSucceeded(SyncKind $kind): ?self
    {
        return static::query()
            ->where('kind', $kind)
            ->where('status', SyncStatus::Succeeded)
            ->latest('finished_at')
            ->first();
    }

    public function durationSeconds(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at);
    }
}
