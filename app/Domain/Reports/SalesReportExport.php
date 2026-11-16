<?php

namespace App\Domain\Reports;

use App\Domain\Catalog\Catalog;
use App\Support\EvolizState;
use App\Support\Profile\Brand;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * "Ventes" as an Excel workbook: "Résumé" (filters and totals; HT, TVA and
 * TTC are formulas over "Détail"), one sheet per grouping, and "Détail" with
 * every line of every BL, its Statut (Bon / Facturé), and the BL totals on the
 * BL's first line so that the column sums are the report's. Numbers are
 * numbers, dates are Excel dates, every totals row is a formula. In file mode
 * the rows are exported orders, and the wording says so.
 */
class SalesReportExport
{
    public const TITLE = 'Ventes';

    /**
     * Sheet names (Excel forbids "/" in them): the profile's wording of the groupings.
     *
     * @return array<string, string>
     */
    private static function sheets(): array
    {
        return [
            // No sheet by category when the profile has none.
            ...(Catalog::categories() !== [] ? ['category' => __('By category')] : []),
            'article' => __('By article'),
            'client' => 'Par client',
            'month' => 'Par mois',
            'department' => 'Par département',
            'variant' => __('sales.sheet.variant'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function groupHeaders(): array
    {
        return ['Libellé', ! SalesReport::fromEvoliz() ? 'Nb commandes' : 'Nb BL', __('Quantities'), 'dont offertes', 'HT', 'TTC'];
    }

    /**
     * @return list<string>
     */
    private static function detailHeaders(): array
    {
        return ! SalesReport::fromEvoliz()
            ? ['N° commande', 'Date', 'Client', 'Statut', 'Vendeur', 'Référence', 'Désignation', 'Qté', 'PU HT', 'Offerte', 'TVA %', 'Total ligne HT', 'Commande HT', 'Commande TVA', 'Commande TTC']
            : ['N° BL', 'Date', 'Client', 'Statut', 'Vendeur', 'Référence', 'Désignation', 'Qté', 'PU HT', 'Offerte', 'TVA %', 'Total ligne HT', 'BL HT', 'BL TVA', 'BL TTC'];
    }

    /**
     * @param  array<string, mixed>  $input  SalesReport::input()
     * @param  array<string, mixed>  $result  SalesReport::aggregate()
     */
    public function spreadsheet(array $input, array $result): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setTitle(self::TITLE)->setCreator(Brand::name());
        $summary = $book->getActiveSheet()->setTitle('Résumé');

        foreach (self::sheets() as $grouping => $title) {
            $this->groupSheet($book->createSheet()->setTitle($title), $grouping, $result['groups'][$grouping]);
        }
        $detailTotals = $this->detailSheet($book->createSheet()->setTitle('Détail'), $input, $result);
        $this->summarySheet($summary, $input['filters'], $result['cards'], $detailTotals);

        foreach ($book->getAllSheets() as $sheet) {
            $sheet->getHeaderFooter()->setOddHeader('&L&B'.self::TITLE.'&B · '.self::period($input['filters']))->setOddFooter('&RPage &P / &N');
            $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $result
     */
    public function write(array $input, array $result, string $target): void
    {
        $writer = new Xlsx($this->spreadsheet($input, $result));
        $writer->setPreCalculateFormulas(false);
        $writer->save($target);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $cards
     * @param  int  $detailTotals  row of the totals in "Détail"
     */
    private function summarySheet(Worksheet $sheet, array $filters, array $cards, int $detailTotals): void
    {
        $statuses = array_filter([$filters['delivered'] ? 'Bons de livraison' : null, $filters['invoiced'] ? 'Facturés' : null]);
        $file = ! SalesReport::fromEvoliz();
        // Evoliz switched on but not running (keys, licence): the orders sent before it stopped count too.
        $stopped = $file && EvolizState::enabled();
        $rows = [
            ['Période', self::period($filters)],
            ['Statuts', $file ? ($stopped ? 'Commandes livrées' : 'Commandes exportées') : implode(', ', $statuses)],
            ['Vendeur', $filters['seller_name'] ?? 'Tous'],
            ['Source', match (true) {
                $stopped => 'Commandes envoyées à Evoliz ou exportées (Evoliz ne tourne pas : les BL faits dans Evoliz ne sont pas comptés)',
                $file => 'Commandes exportées (les autres commandes ne sont pas comptées)',
                default => 'Bons de livraison Evoliz (les commandes en attente ne sont pas comptées)',
            }],
            [],
            [$file ? 'Nombre de commandes' : 'Nombre de BL', $cards['count']],
            [__('sales.card.quantities'), ReportSheet::number($cards['quantity'])],
            ['dont offertes', ReportSheet::number($cards['free_quantity'])],
            ['HT', "='Détail'!M{$detailTotals}"],
            ['TVA', "='Détail'!N{$detailTotals}"],
            ['TTC', "='Détail'!O{$detailTotals}"],
        ];
        $sheet->fromArray($rows, null, 'A1', true);
        $sheet->getStyle('B9:B11')->getNumberFormat()->setFormatCode('#,##0.00 €');
        $sheet->getStyle('A1:A11')->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setWidth(24);
        $sheet->getColumnDimension('B')->setWidth(60);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function groupSheet(Worksheet $sheet, string $grouping, array $rows): void
    {
        $headers = self::groupHeaders();
        $sheet->fromArray($headers);
        $line = 2;
        foreach ($rows as $row) {
            $sheet->fromArray([
                self::label($grouping, $row),
                $row['count'],
                ReportSheet::number($row['quantity']),
                ReportSheet::number($row['free_quantity']),
                ReportSheet::number($row['ht']),
                ReportSheet::number($row['ttc']),
            ], null, "A{$line}", true);
            $line++;
        }

        $last = $line - 1;
        $sheet->setCellValue("A{$line}", 'TOTAL');
        // A BL split over several categories or articles counts in each: the BL count only adds up per client, month or département.
        $columns = in_array($grouping, ['client', 'month', 'department'], true) ? ['B', 'C', 'D', 'E', 'F'] : ['C', 'D', 'E', 'F'];
        foreach ($columns as $column) {
            $sheet->setCellValue("{$column}{$line}", $last > 1 ? "=SUM({$column}2:{$column}{$last})" : 0);
        }
        $sheet->getStyle("E2:F{$line}")->getNumberFormat()->setFormatCode('#,##0.00 €');

        ReportSheet::finish($sheet, count($headers), $line, [34, 9, 10, 13, 14, 14]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $result
     * @return int the totals row
     */
    private function detailSheet(Worksheet $sheet, array $input, array $result): int
    {
        $headers = self::detailHeaders();
        $sheet->fromArray($headers);
        $deliveries = [];
        foreach ($input['deliveries'] as $delivery) {
            $deliveries[$delivery['id']] = $delivery;
        }
        $linesOf = [];
        foreach ($input['lines'] as $line) {
            $linesOf[$line['delivery_id']][] = $line;
        }

        $row = 2;
        foreach ($result['deliveries'] as $listed) {
            $delivery = $deliveries[$listed['id']];
            $head = [
                $listed['number'],
                ExcelDate::stringToExcel($listed['date']),
                $listed['client'],
                match (true) {
                    ! SalesReport::fromEvoliz() => ($delivery['exported'] ?? true) ? 'Exportée' : 'Bon',
                    $listed['invoiced'] => 'Facturé',
                    default => 'Bon',
                },
                $delivery['seller'] ?? '',
            ];
            $totals = [ReportSheet::number($delivery['total_ht']), ReportSheet::number($delivery['total_vat']), ReportSheet::number($delivery['total_ttc'])];
            $lines = $linesOf[$listed['id']] ?? [null];

            foreach ($lines as $index => $line) {
                $sheet->fromArray([
                    ...$head,
                    $line['reference'] ?? null,
                    $line['designation'] ?? null,
                    $line !== null ? ReportSheet::number($line['quantity']) : null,
                    $line !== null ? ReportSheet::number($line['unit_price_ht']) : null,
                    $line !== null && $line['is_free'] ? 'Oui' : null,
                    $line !== null ? ReportSheet::number($line['vat_rate']) : null,
                    $line !== null ? ReportSheet::number($line['total_ht']) : null,
                    ...($index === 0 ? $totals : [null, null, null]),
                ], null, "A{$row}", true);
                $row++;
            }
        }

        $last = $row - 1;
        if ($last > 1) {
            $sheet->getStyle("B2:B{$last}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            $sheet->getStyle("G2:G{$last}")->getAlignment()->setWrapText(true);
        }
        $sheet->setCellValue("A{$row}", 'TOTAL');
        foreach (['H', 'L', 'M', 'N', 'O'] as $column) {
            $sheet->setCellValue("{$column}{$row}", $last > 1 ? "=SUM({$column}2:{$column}{$last})" : 0);
        }
        $sheet->getStyle("I2:I{$row}")->getNumberFormat()->setFormatCode('#,##0.00 €');
        $sheet->getStyle("L2:O{$row}")->getNumberFormat()->setFormatCode('#,##0.00 €');

        ReportSheet::finish($sheet, count($headers), $row, [16, 11, 30, 9, 18, 20, 50, 7, 10, 8, 7, 13, 12, 11, 12]);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function label(string $grouping, array $row): string
    {
        if ($row['label'] === null) {
            return __('sales.'.$row['key']);
        }

        return $grouping === 'month' ? ucfirst(Carbon::parse($row['key'].'-01')->locale('fr')->translatedFormat('F Y')) : (string) $row['label'];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private static function period(array $filters): string
    {
        return Carbon::parse($filters['from'])->format('d/m/Y').' – '.Carbon::parse($filters['to'])->format('d/m/Y');
    }
}
