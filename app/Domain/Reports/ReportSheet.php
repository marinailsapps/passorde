<?php

namespace App\Domain\Reports;

use App\Support\Profile\Brand;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * What the Excel downloads of the reports share: the header and totals
 * styling, numbers as numbers and the Evoliz stock wording.
 */
final class ReportSheet
{
    /**
     * Bold header, frozen first row, filters over the data, widths, bold totals row.
     *
     * @param  list<int>  $widths
     */
    public static function finish(Worksheet $sheet, int $columns, int $totalsRow, array $widths): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex($columns);
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColumn}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(Brand::accentSoftRgb());
        $sheet->getStyle("A{$totalsRow}:{$lastColumn}{$totalsRow}")->getFont()->setBold(true);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:'.$lastColumn.max(1, $totalsRow - 1));
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }
    }

    /**
     * Where a broken entry or gift stands with the Evoliz stock.
     *
     * @param  array<string, mixed>  $row
     */
    public static function evolizLabel(array $row): string
    {
        if (($row['source'] ?? null) === 'order') {
            return 'Par le BL';
        }

        return match ($row['evoliz_status'] ?? null) {
            'sent' => 'Déduit',
            'pending' => 'En attente',
            'failed' => 'Échec',
            'disabled' => 'Non envoyé (mode test)',
            'skipped' => 'Sans gestion de stock',
            default => '',
        };
    }

    public static function number(mixed $value): float|int|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
    }
}
