<?php

namespace App\Domain\Import;

use Illuminate\Support\Str;

/**
 * Reads what spreadsheets and accounting software call a CSV: separator ; or ,
 * (detected from the header), UTF-8 with or without BOM or Windows-1252, header
 * names in any case, and other software's names for a column through aliases.
 * Works on the upload's contents only: nothing is stored.
 */
final class CsvReader
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * @param  list<string>  $columns  The template's columns
     * @param  list<string>  $required  Columns the file cannot do without
     * @param  array<string, string>  $aliases  Another header (as written, any case or accents) => the column it fills
     *
     * @throws CsvRejected
     */
    public static function read(string $contents, array $columns, array $required, array $aliases = []): CsvFile
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw new CsvRejected(__('The file is larger than :size MB.', ['size' => self::MAX_BYTES / 1024 / 1024]));
        }

        // An Excel workbook or a UTF-16 text export: never a CSV this reader can use.
        if (str_contains($contents, "\0")) {
            throw new CsvRejected(__('This is not a CSV file: in the spreadsheet, save it as CSV.'));
        }

        $text = preg_replace('/\r\n?/', "\n", self::toUtf8($contents)) ?? '';
        $stream = fopen('php://temp', 'r+');
        assert($stream !== false);
        fwrite($stream, $text);
        rewind($stream);

        $separator = self::separator($text);
        $header = fgetcsv($stream, null, $separator, '"', '');

        if ($header === false || $header === [null]) {
            throw new CsvRejected(__('The file is empty.'));
        }

        $known = [...$columns, ...array_values($aliases)];
        $aliased = [];

        foreach ($aliases as $alias => $column) {
            $aliased[self::headerName($alias)] = $column;
        }

        $ignored = [];
        $positions = [];

        foreach ($header as $position => $name) {
            $column = self::headerName((string) $name);

            if ($column === '') {
                continue;
            }

            $column = $aliased[$column] ?? $column;

            if (! in_array($column, $known, true) || isset($positions[$column])) {
                $ignored[] = trim((string) $name);

                continue;
            }

            $positions[$column] = $position;
        }

        $warnings = $ignored === [] ? [] : [(string) __('Columns ignored: :columns.', ['columns' => implode(', ', $ignored)])];

        foreach ($required as $column) {
            if (! isset($positions[$column])) {
                throw new CsvRejected(__('Column ":column" is missing: nothing was imported.', ['column' => $column]));
            }
        }

        $rows = [];
        $line = 1;

        while (($row = fgetcsv($stream, null, $separator, '"', '')) !== false) {
            $line++;

            $cells = [];
            foreach ($positions as $column => $position) {
                $cells[$column] = trim((string) ($row[$position] ?? ''));
            }

            if (implode('', $cells) !== '') {
                $rows[] = ['line' => $line, 'cells' => $cells];
            }
        }

        fclose($stream);

        return new CsvFile($rows, $warnings);
    }

    /**
     * A number as people write it: decimal comma or point, spaces between thousands.
     * Normalised with a point; null when it is not a number.
     */
    public static function decimal(string $value): ?string
    {
        $number = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $value) ?? '';
        $comma = strrpos($number, ',');
        $point = strrpos($number, '.');

        // Both present: the last one is the decimal separator.
        if ($comma !== false && $point !== false) {
            $number = str_replace($comma > $point ? '.' : ',', '', $number);
        }

        $number = str_replace(',', '.', $number);

        return preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $number) === 1 ? $number : null;
    }

    /** "oui" or "non" (or o, n, 1, 0, true, false, and x for yes), in any case; null for anything else. */
    public static function yesNo(string $value): ?bool
    {
        return match (mb_strtolower($value)) {
            'oui', 'o', '1', 'true', 'x' => true,
            'non', 'n', '0', 'false' => false,
            default => null,
        };
    }

    /**
     * Whether the row says the record is active: "actif", or the opposite of
     * Evoliz's "Désactivé" (aliased to the column "desactive"). Null when neither says.
     *
     * @param  array<string, string>  $cells
     *
     * @throws RowRejected
     */
    public static function active(array $cells): ?bool
    {
        foreach (['actif' => false, 'desactive' => true] as $column => $inverted) {
            if (($cells[$column] ?? '') !== '') {
                $yes = self::yesNo($cells[$column]) ?? throw new RowRejected(__('Column ":column": oui or non expected.', ['column' => $column]));

                return $inverted ? ! $yes : $yes;
            }
        }

        return null;
    }

    /** "Code Postal" and "code_postal" are the same column, and accents do not matter. */
    private static function headerName(string $name): string
    {
        $name = Str::lower(Str::ascii(trim($name)));

        return trim(preg_replace('/[^a-z0-9]+/', '_', $name) ?? '', '_');
    }

    private static function toUtf8(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        return mb_check_encoding($contents, 'UTF-8') ? $contents : (string) mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
    }

    /** The one that appears more often in the header, outside quotes; ";" when it is a tie. */
    private static function separator(string $text): string
    {
        $header = strtok($text, "\n");
        $outside = preg_replace('/"[^"]*"/', '', $header === false ? '' : $header) ?? '';

        return substr_count($outside, ',') > substr_count($outside, ';') ? ',' : ';';
    }
}
