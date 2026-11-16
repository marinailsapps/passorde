<?php

namespace App\Domain\Orders;

use App\Domain\Clients\ClientExtraField;
use App\Domain\Reports\ReportSheet;
use App\Models\Order;
use App\Models\OrderLine;
use App\Support\Profile\Brand;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * File mode: approved orders as a file for retyping into the invoicing
 * software, one row per order line (docs/RUNBOOK.md "Standalone mode: export"). The
 * CSV opens directly in French Excel: `;`, UTF-8 with BOM, decimal comma.
 *
 * Client details and seller comments are outside the operator's control, so a
 * text cell must never run as a formula: explicit strings in the Excel file, a
 * leading apostrophe in the CSV.
 */
class OrderExport
{
    public const TITLE = 'Commandes';

    /** Orders in one export file (CSV or Excel), and in one "mark as exported" (the file's orders). The admin list's uuids travel in the URL: past about 160 the request line exceeds the server's 8 KB. */
    public const MAX_ORDERS = 150;

    private const RELATIONS = ['user', 'client', 'paytype', 'lines.article'];

    /** Placeholder header of the optional client field's column (columns()). */
    private const EXTRA_HEADER = '~extra';

    /** Header and kind of each column. */
    private const COLUMNS = [
        ['N° commande', 'text'],
        ['Date commande', 'date'],
        ['Date validation', 'date'],
        ['Vendeur', 'text'],
        ['Code client', 'text'],
        ['Client à créer', 'text'],
        ['Société', 'text'],
        ['Contact', 'text'],
        ['Adresse', 'text'],
        ['Code postal', 'text'],
        ['Ville', 'text'],
        ['Téléphone', 'text'],
        ['Mobile', 'text'],
        ['Email', 'text'],
        // The optional client field: header = its label (ClientExtraField); left out while it is off.
        [self::EXTRA_HEADER, 'text'],
        ['Mode de paiement', 'text'],
        ['Commentaire commande', 'text'],
        ['Ligne', 'number'],
        ['Référence', 'text'],
        ['Désignation', 'text'],
        ['Remarque ligne', 'text'],
        ['Offerte', 'text'],
        ['Quantité', 'number'],
        ['PU HT', 'money'],
        ['TVA %', 'number'],
        ['Total ligne HT', 'money'],
        ['Total commande HT', 'money'],
        ['TVA', 'money'],
        ['TTC', 'money'],
    ];

    private const WIDTHS = [14, 16, 16, 18, 12, 8, 30, 20, 28, 9, 18, 15, 15, 24, 11, 16, 30, 6, 20, 50, 24, 8, 9, 10, 7, 12, 12, 11, 12];

    /**
     * @param  iterable<Order>  $orders
     * @return list<list<mixed>> one row per order line, in the order of columns()
     */
    public function rows(iterable $orders): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $order->loadMissing(self::RELATIONS);
            $client = $order->client;

            foreach ($order->lines as $line) {
                /** @var OrderLine $line */
                $rows[] = [
                    $order->number,
                    $order->created_at,
                    $order->approved_at,
                    $order->user->name,
                    $client->evoliz_code,
                    filled($client->evoliz_code) ? 'non' : 'oui',
                    $client->company_name,
                    $client->contact_name,
                    $client->address_line,
                    $client->postcode,
                    $client->town,
                    $client->phone,
                    $client->mobile,
                    $client->email,
                    $client->extra_field,
                    $order->paytype?->label,
                    $order->comment,
                    $line->position + 1,
                    $line->article?->reference,
                    $line->designation,
                    $line->note,
                    $line->is_free ? 'oui' : 'non',
                    $line->quantity,
                    $line->unit_price_ht,
                    $line->vat_rate,
                    $line->total_ht,
                    $order->total_ht,
                    $order->total_vat,
                    $order->total_ttc,
                ];
            }
        }

        return self::withoutExtra($rows);
    }

    /**
     * Header and kind of each column, the optional client field named by its label or left out.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function columns(): array
    {
        $index = self::extraIndex();
        $columns = self::COLUMNS;

        if (ClientExtraField::enabled()) {
            $columns[$index][0] = ClientExtraField::label();

            return $columns;
        }

        return array_values(array_filter($columns, fn (int $i) => $i !== $index, ARRAY_FILTER_USE_KEY));
    }

    /**
     * @return list<int>
     */
    private static function widths(): array
    {
        return ClientExtraField::enabled() ? self::WIDTHS : array_values(array_filter(self::WIDTHS, fn (int $i) => $i !== self::extraIndex(), ARRAY_FILTER_USE_KEY));
    }

    private static function extraIndex(): int
    {
        return (int) array_search(self::EXTRA_HEADER, array_column(self::COLUMNS, 0), true);
    }

    /**
     * @param  list<list<mixed>>  $rows  in the order of COLUMNS
     * @return list<list<mixed>> in the order of columns()
     */
    private static function withoutExtra(array $rows): array
    {
        if (ClientExtraField::enabled()) {
            return $rows;
        }

        $index = self::extraIndex();

        return array_map(fn (array $row) => array_values(array_filter($row, fn (int $i) => $i !== $index, ARRAY_FILTER_USE_KEY)), $rows);
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    public function writeCsv(array $rows, string $target): void
    {
        $file = fopen($target, 'wb');

        if ($file === false) {
            throw new \RuntimeException("Cannot write the export to {$target}");
        }

        fwrite($file, "\xEF\xBB\xBF");
        $columns = self::columns();
        self::putCsv($file, array_column($columns, 0));

        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $index => $value) {
                $cells[] = self::csvCell($value, $columns[$index][1]);
            }
            self::putCsv($file, $cells);
        }

        fclose($file);
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    public function spreadsheet(array $rows): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setTitle(self::TITLE)->setCreator(Brand::name());
        $sheet = $book->getActiveSheet()->setTitle(self::TITLE);
        $columns = self::columns();
        $sheet->fromArray(array_column($columns, 0));

        $line = 2;
        foreach ($rows as $row) {
            foreach ($row as $index => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $cell = Coordinate::stringFromColumnIndex($index + 1).$line;

                match ($columns[$index][1]) {
                    'text' => $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING),
                    'date' => $sheet->setCellValue($cell, ExcelDate::PHPToExcel(self::paris($value))),
                    default => $sheet->setCellValue($cell, ReportSheet::number($value)),
                };
            }
            $line++;
        }

        $last = $line - 1;
        if ($last > 1) {
            foreach ($columns as $index => [, $kind]) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                match ($kind) {
                    'date' => $sheet->getStyle("{$column}2:{$column}{$last}")->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm'),
                    'money' => $sheet->getStyle("{$column}2:{$column}{$last}")->getNumberFormat()->setFormatCode('#,##0.00 €'),
                    default => null,
                };
            }
        }

        ReportSheet::finish($sheet, count($columns), $line, self::widths());
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(1, 1);

        return $book;
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    public function writeXlsx(array $rows, string $target): void
    {
        (new Xlsx($this->spreadsheet($rows)))->save($target);
    }

    /**
     * @param  resource  $file
     * @param  list<string>  $cells
     */
    private static function putCsv($file, array $cells): void
    {
        fputcsv($file, $cells, ';', '"', '', "\r\n");
    }

    private static function csvCell(mixed $value, string $kind): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($kind) {
            'text' => self::safeText((string) $value),
            'date' => self::paris($value)->format('d/m/Y H:i'),
            default => str_replace('.', ',', (string) $value),
        };
    }

    /**
     * Excel would run a cell that starts with these. A plain international
     * phone number cannot, and an apostrophe would have to be retyped away.
     */
    private static function safeText(string $text): string
    {
        if (preg_match('/^\+[\d .]+$/', $text) === 1) {
            return $text;
        }

        return preg_match('/^\s*[=+\-@]/', $text) === 1 || preg_match('/^[\t\r\n]/', $text) === 1 ? "'".$text : $text;
    }

    private static function paris(mixed $date): Carbon
    {
        return Carbon::parse($date)->timezone('Europe/Paris');
    }
}
