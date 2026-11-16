<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $login
 * @property string|null $email
 * @property string|null $phone
 * @property Role $role
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'login',
        'email',
        'phone',
        'password',
        'role',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /**
     * Sign the user out of every device: drop their stored sessions (except
     * $keepSessionId, the caller's own) and invalidate "remember me" by
     * rotating the token. The caller saves the model.
     */
    public function endSessions(?string $keepSessionId = null): void
    {
        $this->setRememberToken(Str::random(60));

        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $this->id)
                ->when($keepSessionId !== null, fn ($query) => $query->where('id', '!=', $keepSessionId))
                ->delete();
        }
    }

    /**
     * Find a user by login handle or email address, case-insensitively.
     */
    public static function findByIdentifier(string $identifier): ?self
    {
        $identifier = mb_strtolower(trim($identifier));

        if ($identifier === '') {
            return null;
        }

        return static::query()
            ->where('login', $identifier)
            ->orWhere('email', $identifier)
            ->first();
    }

    /**
     * Password resets are addressed to the email; users without one cannot reset.
     */
    public function getEmailForPasswordReset(): string
    {
        return (string) $this->email;
    }

    /**
     * @return Attribute<string, string>
     */
    protected function login(): Attribute
    {
        return Attribute::set(fn (string $value) => mb_strtolower(trim($value)));
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function email(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value === null || trim($value) === '' ? null : mb_strtolower(trim($value)));
    }
}
