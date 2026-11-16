<?php

namespace App\Domain\Clients;

use App\Domain\Import\CsvReader;
use App\Domain\Import\ImportReport;
use App\Domain\Import\RowRejected;
use App\Domain\Sms\FrenchMobile;
use App\Enums\ClientChangeSource;
use App\Enums\ClientSource;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The client directory from a CSV file, in file mode.
 * Key: code, stored in evoliz_code, among rows with no Evoliz id. An empty cell never
 * erases, rows absent from the file are left alone, and only real changes save; each
 * goes into client_changes. A new code may adopt the one typed client with no code and
 * the same company and postcode, instead of duplicating it.
 */
final class ClientImport
{
    /** The template's columns without the optional client field (columns() puts it before "actif"). */
    public const BASE_COLUMNS = ['code', 'societe', 'contact', 'adresse', 'code_postal', 'ville', 'telephone', 'mobile', 'email', 'actif'];

    /** A fictional row for the downloadable template, in BASE_COLUMNS order. */
    private const TEMPLATE_BASE = ['C00001', "SARL DE L'EXEMPLE", 'Solène Exemple', "1 rue de l'Exemple", '72100', 'EXEMPLEVILLE', '09 72 00 00 00', '06 00 00 00 00', 'contact@example.test', 'oui'];

    /** The example value of the optional client field in the template. */
    private const TEMPLATE_EXTRA = 'REF-0001';

    /** Longest value of the optional client field (clients.extra_field). */
    private const EXTRA_MAX = 50;

    /**
     * The Evoliz client list's CSV export. Every other column is ignored, on purpose
     * the banking ones and "Commentaires": personal data the app has no use for.
     */
    public const ALIASES = [
        'Code' => 'code',
        'Société / Nom' => 'societe',
        'Adresse' => 'adresse',
        "Complément d'adresse" => 'complement_adresse',
        'Code postal' => 'code_postal',
        'Ville' => 'ville',
        'Téléphone' => 'telephone',
        'Portable' => 'mobile',
        'Désactivé' => 'desactive',
    ];

    /** Client column and longest value of each text column of the template. */
    private const TEXT = [
        'societe' => ['company_name', 120],
        'contact' => ['contact_name', 120],
        'adresse' => ['address_line', 255],
        'code_postal' => ['postcode', 10],
        'ville' => ['town', 120],
        'telephone' => ['phone', 255],
        'mobile' => ['mobile', 255],
        'email' => ['email', 255],
    ];

    /**
     * The template's columns: the optional client field (profile client.extra_field.csv) comes before "actif".
     *
     * @return list<string>
     */
    public static function columns(): array
    {
        return self::withExtra(self::BASE_COLUMNS, ClientExtraField::csv());
    }

    /**
     * @return list<string>
     */
    public static function templateRow(): array
    {
        return self::withExtra(self::TEMPLATE_BASE, ClientExtraField::enabled() ? self::TEMPLATE_EXTRA : null);
    }

    /**
     * @param  list<string>  $list
     * @return list<string>
     */
    private static function withExtra(array $list, ?string $extra): array
    {
        return $extra === null ? $list : [...array_slice($list, 0, -1), $extra, ...array_slice($list, -1)];
    }

    /**
     * Template column => [client attribute, longest value] of each text column, the optional field included.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    private static function text(): array
    {
        $csv = ClientExtraField::csv();

        return $csv === null ? self::TEXT : [...self::TEXT, $csv => [ClientExtraField::ATTRIBUTE, self::EXTRA_MAX]];
    }

    public function run(string $contents, User $user, bool $dryRun): ImportReport
    {
        $csv = CsvReader::read($contents, self::columns(), ['code'], self::ALIASES);

        // Every row with no Evoliz id: an admin may have given a typed client its code.
        $coded = Client::query()->whereNull('evoliz_client_id')->whereNotNull('evoliz_code')->get()
            ->keyBy(fn (Client $client) => ImportReport::key((string) $client->evoliz_code));
        $typed = Client::query()->whereNull('evoliz_client_id')->whereNull('evoliz_code')->where('source', ClientSource::App)->get()
            ->groupBy(fn (Client $client) => self::identity($client->company_name, $client->postcode));

        return ImportReport::run('clients', 'code', $csv, $user, $dryRun, function (int $line, string $key, array $cells, ImportReport $report) use ($coded, $typed, $user) {
            $warnings = [];
            $values = self::values($cells, $warnings);
            $client = $coded->get(ImportReport::key($key));
            $creating = false;

            if ($client === null) {
                $company = $values['company_name'] ?? throw new RowRejected(__('New client: :columns required.', ['columns' => 'societe']));
                $identity = self::identity($company, $values['postcode'] ?? null);
                $matches = $typed->get($identity);

                if ($matches !== null && $matches->count() === 1) {
                    $client = $matches->first();
                    $typed->forget($identity);
                    $client->fill(['evoliz_code' => $key, 'source' => ClientSource::Import]);
                    $warnings[] = (string) __('Takes over the client typed in the app: :company.', ['company' => $client->company_name]);
                } else {
                    if ($matches !== null) {
                        $warnings[] = (string) __('Several clients typed in the app look like this one: created as a new client, check for duplicates.');
                    }

                    $creating = true;
                    $client = new Client(['evoliz_code' => $key, 'source' => ClientSource::Import, 'is_active' => true]);
                }
            }

            $client->fill($values);
            $fields = self::changedColumns($client);
            $changed = $client->isDirty();

            if (! $creating && $changed) {
                ClientChanges::recordDirty($client, ClientChangeSource::Import, $user->id);
            }

            $client->save();

            $action = match (true) {
                $creating => ImportReport::CREATED,
                $changed => ImportReport::UPDATED,
                default => ImportReport::UNCHANGED,
            };

            $report->add($line, $key, $action, $creating ? [] : $fields, $warnings);
        });
    }

    /**
     * The client columns the row states.
     *
     * @param  array<string, string>  $cells
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private static function values(array $cells, array &$warnings): array
    {
        $values = [];
        $cells['adresse'] = implode(', ', array_filter([$cells['adresse'] ?? '', $cells['complement_adresse'] ?? ''], fn (string $part) => $part !== ''));

        foreach (self::text() as $column => [$attribute, $max]) {
            $cell = $cells[$column] ?? '';

            if ($cell === '') {
                continue;
            }

            if (mb_strlen($cell) > $max) {
                throw new RowRejected(__('Column ":column" is too long.', ['column' => $column]));
            }

            $values[$attribute] = $cell;
        }

        if (isset($values['postcode']) && preg_match('/^\d{5}$/', $values['postcode']) !== 1) {
            $warnings[] = (string) __('Postcode ":value" is not 5 digits.', ['value' => $values['postcode']]);
        }

        if (isset($values['mobile']) && FrenchMobile::normalise($values['mobile']) === null) {
            $warnings[] = (string) __('":value" is not a French mobile number: no SMS can go to it.', ['value' => $values['mobile']]);
        }

        if (isset($values['email'])) {
            if (filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
                $warnings[] = (string) __('Email ":value" is not valid: ignored.', ['value' => $values['email']]);
                unset($values['email']);
            } else {
                $values['email'] = mb_strtolower($values['email']);
            }
        }

        $active = CsvReader::active($cells);

        if ($active !== null) {
            $values['is_active'] = $active;
        }

        return $values;
    }

    /**
     * The same company and postcode, whatever the case, accents and spacing.
     */
    private static function identity(string $company, ?string $postcode): string
    {
        return mb_strtolower(Str::squish(Str::ascii($company))).'|'.trim((string) $postcode);
    }

    /**
     * @return list<string> Template columns that change
     */
    private static function changedColumns(Client $client): array
    {
        $columns = $client->isDirty('evoliz_code') ? ['code'] : [];

        foreach (self::text() as $column => [$attribute]) {
            if ($client->isDirty($attribute)) {
                $columns[] = $column;
            }
        }

        if ($client->isDirty('is_active')) {
            $columns[] = 'actif';
        }

        return $columns;
    }
}
