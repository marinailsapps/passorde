<?php

namespace App\Integrations\Sync;

use App\Models\Setting;
use App\Support\EvolizState;

/**
 * The Evoliz keys an admin enters in the settings page (D4), encrypted in the
 * settings table. A key saved there wins over the same PASSORDE_EVOLIZ_* value
 * from .env; an empty one falls back to .env, so an installation configured by
 * environment keeps working untouched. Passorde reads its keys from
 * config('passorde-connect.evoliz.*') at each call: apply() copies the saved ones in, at
 * boot and before each queued job (a long-running worker sees a change at its
 * next job). Values never go back to the browser in full (view()).
 */
final class EvolizSettings
{
    /** Field => config key under passorde-connect.evoliz. */
    public const FIELDS = [
        'company_id' => 'company_id',
        'public_key' => 'public_key',
        'secret_key' => 'secret_key',
    ];

    public static function settingKey(string $field): string
    {
        return 'evoliz.'.$field;
    }

    /**
     * Overlays the saved keys on the configuration; a key no longer saved gets its .env value back.
     */
    public static function apply(): void
    {
        foreach (self::FIELDS as $field => $configKey) {
            $saved = Setting::read(self::settingKey($field));
            $envKey = 'server.evoliz_env.'.$configKey;
            $path = 'passorde-connect.evoliz.'.$configKey;

            if ($saved !== null) {
                if (! config()->has($envKey)) {
                    config()->set($envKey, config($path));
                }

                config()->set($path, $field === 'company_id' ? (int) $saved : $saved);
            } elseif (config()->has($envKey)) {
                config()->set($path, config($envKey));
                config()->set('server.evoliz_env', array_diff_key((array) config('server.evoliz_env'), [$configKey => true]));
            }
        }

        EvolizState::flush();
    }

    /**
     * Saves what the admin typed; an empty field keeps the saved value (secrets are never shown to be retyped).
     *
     * @param  array<string, string|null>  $values
     * @param  list<string>  $clear  Fields to remove from the database (back to .env)
     * @return bool whether a saved value changed
     */
    public static function save(array $values, array $clear, int $userId): bool
    {
        $changed = false;

        foreach (array_keys(self::FIELDS) as $field) {
            $saved = Setting::read(self::settingKey($field));
            if (in_array($field, $clear, true)) {
                $changed = $changed || $saved !== null;
                Setting::write(self::settingKey($field), null, $userId);
            } elseif (filled($values[$field] ?? null)) {
                $value = trim((string) $values[$field]);
                $changed = $changed || $saved !== $value;
                Setting::write(self::settingKey($field), $value, $userId);
            }
        }

        self::apply();

        return $changed;
    }

    /**
     * What the settings page may show: where each key comes from and a hint, never a secret.
     *
     * @return array<string, array{set: bool, source: 'database'|'env'|null, hint: string|null}>
     */
    public static function view(): array
    {
        $view = [];

        foreach (self::FIELDS as $field => $configKey) {
            $saved = Setting::read(self::settingKey($field));
            $value = (string) config('passorde-connect.evoliz.'.$configKey);
            $set = $value !== '' && $value !== '0';

            $view[$field] = [
                'set' => $set,
                'source' => $saved !== null ? 'database' : ($set ? 'env' : null),
                'hint' => ! $set ? null : match ($field) {
                    // The company number is printed on every Evoliz page: not a secret.
                    'company_id' => $value,
                    'public_key' => '…'.mb_substr($value, -4),
                    default => null,
                },
            ];
        }

        return $view;
    }
}
