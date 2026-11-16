<?php

namespace App\Domain\Clients;

/**
 * The installation's one optional free field on clients (profile client.extra_field,
 * config/passorde.php): its label, its CSV column, and whether the client search
 * reads it. Stored in clients.extra_field and sent as `extra_field` in the
 * API, whatever the label says (and under an older name too when the profile has one, aliases.client,
 * App\Http\Api\ApiAliases).
 * Off (null): the field is not shown, imported, exported or searched; values
 * already stored stay in the database and in the API answers.
 */
final class ClientExtraField
{
    /** The client attribute (and API field) that holds the value. */
    public const ATTRIBUTE = 'extra_field';

    /**
     * @return array{label: string, csv: string, searchable: bool}|null
     */
    public static function config(): ?array
    {
        $field = config('passorde.client.extra_field');

        if (! is_array($field) || ! is_string($field['label'] ?? null) || ! is_string($field['csv'] ?? null)) {
            return null;
        }

        return ['label' => $field['label'], 'csv' => $field['csv'], 'searchable' => ($field['searchable'] ?? false) === true];
    }

    public static function enabled(): bool
    {
        return self::config() !== null;
    }

    /** The label, or a neutral one where a text must name it while the field is off (validation). */
    public static function label(): string
    {
        return self::config()['label'] ?? __('Client field');
    }

    public static function csv(): ?string
    {
        return self::config()['csv'] ?? null;
    }

    public static function searchable(): bool
    {
        return self::config()['searchable'] ?? false;
    }

    /**
     * The shared prop clientExtraField: what the pages and the phone need.
     *
     * @return array{label: string, searchable: bool}|null
     */
    public static function view(): ?array
    {
        $field = self::config();

        return $field === null ? null : ['label' => $field['label'], 'searchable' => $field['searchable']];
    }

    /**
     * Validation attribute names for the field under these prefixes ("client.", "client_changes.", "").
     *
     * @param  list<string>  $prefixes
     * @return array<string, string>
     */
    public static function attributes(array $prefixes = ['']): array
    {
        return array_fill_keys(array_map(fn (string $prefix) => $prefix.self::ATTRIBUTE, $prefixes), self::label());
    }
}
