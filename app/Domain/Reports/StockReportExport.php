<?php

namespace App\Domain\Reports;

use App\Domain\Catalog\Catalog;
use App\Support\Profile\Brand;
use App\Support\SyncMode;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The stock report as an Excel workbook, titled reports.stock_title: one sheet per tab, the tabs of the
 * variants that have one first, then the other category groups in the profile's
 * order, then the unclassified, broken and gift tabs; French headers,
 * numbers as numbers, "à terme" and "à commander" as formulas, a totals row,
 * frozen header and filters. In file mode there is no "Stock Evoliz" column.
 */
class StockReportExport
{
    private const FIXED_SHEETS = [StockReport::ALL, StockReport::UNCLASSIFIED, StockReport::BROKEN, StockReport::GIFTS, StockReport::MATERIAL_GIFTS];

    /**
     * Sheet keys in order (only the report's tabs), and the index of the one that opens: the first
     * category group, else the plain list of articles.
     *
     * @param  list<string>  $present  The report's tab keys
     * @return array{0: list<string>, 1: int}
     */
    private static function sheetOrder(array $present): array
    {
        $owned = Catalog::variantGroups();
        $categories = array_values(array_diff(array_column(Catalog::groups(), 'key'), $owned));
        $order = array_values(array_filter([...$owned, ...$categories, ...self::FIXED_SHEETS], fn (string $key) => in_array($key, $present, true)));
        $opens = $categories !== [] ? $categories[0] : StockReport::ALL;
        $index = array_search($opens, $order, true);

        return [$order, $index === false ? 0 : (int) $index];
    }

    /**
     * @param  array{period: array{from: string, to: string}, synced_at: string|null, tabs: list<array<string, mixed>>}  $report
     */
    public function spreadsheet(array $report): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $book->getProperties()->setTitle(StockReport::title())->setCreator(Brand::name());

        $tabs = collect($report['tabs'])->keyBy('key');
        [$order, $active] = self::sheetOrder($tabs->keys()->all());
        foreach ($order as $key) {
            $tab = $tabs[$key];
            $sheet = $book->createSheet()->setTitle(mb_substr($tab['label'], 0, 31));
            match ($tab['kind']) {
                'stock' => $this->stockSheet($sheet, $tab['rows']),
                'broken' => $this->eventSheet($sheet, $tab['rows'], ['Date', 'Référence Article', 'Désignation Article', 'QTE', 'Remarque', 'Saisi par'], ['date', 'reference', 'designation', 'quantity', 'note', 'user'], 4, [11, 20, 60, 8, 30, 18]),
                default => $this->eventSheet($sheet, $tab['rows'], ['Date', 'Origine', 'Client', 'Référence Article', 'Désignation Article', 'QTE', 'Remarque'], ['date', 'origin', 'client', 'reference', 'designation', 'quantity', 'note'], 6, [11, 20, 30, 20, 60, 8, 30]),
            };
        }

        $book->setActiveSheetIndex($active);

        return $book;
    }

    /**
     * @param  array{period: array{from: string, to: string}, synced_at: string|null, tabs: list<array<string, mixed>>}  $report
     */
    public function write(array $report, string $target): void
    {
        $writer = new Xlsx($this->spreadsheet($report));
        $writer->setPreCalculateFormulas(false);
        $writer->save($target);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function stockSheet(Worksheet $sheet, array $rows): void
    {
        $headers = ['Référence Article', 'Désignation Article', __('stock.column.variant'), __('stock.column.category'), 'Qté Stock Réel', 'Qté Réservée',
            (string) config('passorde.reports.write_off_labels.broken'), 'Cadeaux hors cde', 'Qté à terme', 'Seuil', 'À commander', 'Fournisseur', 'Actif'];
        $sheet->fromArray($headers);

        $line = 2;
        foreach ($rows as $row) {
            $sheet->fromArray([
                $row['reference'],
                $row['designation'],
                $row['type_label'],
                $row['category'],
                ReportSheet::number($row['stock']),
                ReportSheet::number($row['reserved']),
                ReportSheet::number($row['broken']),
                ReportSheet::number($row['gifts']),
            ], null, "A{$line}", true);
            // À terme is the sum of the stock and the (negative) deductions.
            if ($row['stock'] !== null) {
                $sheet->setCellValue("I{$line}", "=SUM(E{$line}:H{$line})");
            }
            if ($row['threshold'] !== null) {
                $sheet->setCellValue("J{$line}", $row['threshold']);
                if ($row['stock'] !== null) {
                    $sheet->setCellValue("K{$line}", "=MAX(0,J{$line}-I{$line})");
                }
            }
            $sheet->setCellValue("L{$line}", $row['supplier']);
            $sheet->setCellValue("M{$line}", $row['active'] ? 'Oui' : 'Non');
            $line++;
        }

        $last = max(1, $line - 1);
        $sheet->setCellValue("A{$line}", 'TOTAL');
        foreach (['E', 'F', 'G', 'H', 'I', 'K'] as $column) {
            $sheet->setCellValue("{$column}{$line}", $last > 1 ? "=SUM({$column}2:{$column}{$last})" : 0);
        }

        ReportSheet::finish($sheet, count($headers), $line, [20, 60, 11, 16, 13, 13, 13, 15, 12, 8, 12, 22, 8]);

        // Since the journal writes to Evoliz, these two columns hold only what Evoliz's stock does not include yet.
        $file = SyncMode::isFile();
        $help = $file
            ? 'Les saisies sont déduites du stock dès leur enregistrement : elles sont déjà dans la colonne Qté Stock Réel.'
            : 'Seulement les saisies pas encore déduites du stock Evoliz (mode test, envoi en attente ou en échec). Les autres sont déjà dans la colonne Qté Stock Réel.';
        $sheet->getComment('M1')->setAuthor(Brand::shortName())->getText()->createTextRun(__($file ? 'stock.comment.active_file' : 'stock.comment.active_evoliz'));
        $sheet->getComment('M1')->setWidth('220pt')->setHeight('70pt');
        foreach (['G1', 'H1'] as $cell) {
            $sheet->getComment($cell)->setAuthor(Brand::shortName())->getText()->createTextRun($help);
            $sheet->getComment($cell)->setWidth('220pt')->setHeight('70pt');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $headers
     * @param  list<string>  $fields
     * @param  list<int>  $widths
     */
    private function eventSheet(Worksheet $sheet, array $rows, array $headers, array $fields, int $quantityColumn, array $widths): void
    {
        if (! SyncMode::isFile()) {
            $headers[] = 'Stock Evoliz';
            $fields[] = 'evoliz';
            $widths[] = 24;
        }

        $sheet->fromArray($headers);

        $line = 2;
        foreach ($rows as $row) {
            $row['origin'] = ($row['source'] ?? null) === 'order' ? 'Commande '.$row['order_number'] : 'Hors commande';
            $row['evoliz'] = ReportSheet::evolizLabel($row);
            $sheet->fromArray(array_map(fn (string $field) => match ($field) {
                'quantity' => ReportSheet::number($row[$field]),
                'date' => ExcelDate::stringToExcel($row[$field]),
                default => $row[$field] ?? null,
            }, $fields), null, "A{$line}", true);
            $line++;
        }
        if ($line > 2) {
            $sheet->getStyle('A2:A'.($line - 1))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);
        }

        $column = Coordinate::stringFromColumnIndex($quantityColumn);
        $last = max(1, $line - 1);
        $sheet->setCellValue("A{$line}", 'TOTAL');
        $sheet->setCellValue("{$column}{$line}", $last > 1 ? "=SUM({$column}2:{$column}{$last})" : 0);

        ReportSheet::finish($sheet, count($headers), $line, $widths);
    }
}
