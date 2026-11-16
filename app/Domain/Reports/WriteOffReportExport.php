<?php

namespace App\Domain\Reports;

use App\Enums\WriteOffType;
use App\Support\Profile\Brand;
use App\Support\SyncMode;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The write-off report as one Excel sheet: the rows of the page for the
 * same period and types, French headers, dates and times as Excel dates,
 * quantities as numbers, a totals row, frozen header and filters. The period
 * and the types are in the printed page header. In file mode there is no
 * "Stock Evoliz" column.
 */
class WriteOffReportExport
{
    /** The report's title and sheet name (reports.write_offs_title, cut to Excel's 31 characters). */
    public static function title(): string
    {
        return (string) config('passorde.reports.write_offs_title');
    }

    private const HEADERS = ['Date', 'Saisi le', 'Type', 'Référence Article', 'Désignation Article', 'QTE', 'Client', 'N° commande', 'Remarque', 'Saisi par'];

    private const WIDTHS = [11, 16, 24, 20, 50, 8, 30, 13, 30, 18];

    /**
     * @param  list<array<string, mixed>>  $rows  WriteOffReport::rows()
     * @param  list<WriteOffType>  $types
     */
    public function spreadsheet(array $rows, Carbon $from, Carbon $to, array $types): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setTitle(self::title())->setCreator(Brand::name());
        $sheet = $book->getActiveSheet()->setTitle(mb_substr(self::title(), 0, 31));
        $evoliz = ! SyncMode::isFile();
        $headers = $evoliz ? [...self::HEADERS, 'Stock Evoliz'] : self::HEADERS;
        $sheet->fromArray($headers);

        $line = 2;
        foreach ($rows as $row) {
            $type = WriteOffType::from($row['type']);
            $sheet->fromArray([
                ExcelDate::stringToExcel($row['date']),
                ExcelDate::PHPToExcel(Carbon::parse($row['recorded_at'])->timezone('Europe/Paris')),
                $type->label(),
                $row['reference'],
                $row['designation'],
                ReportSheet::number($row['quantity']),
                $row['client'],
                $row['order_number'],
                $row['note'],
                $row['user'],
                ...($evoliz ? [ReportSheet::evolizLabel(['source' => $type === WriteOffType::OrderGift ? 'order' : 'adjustment', 'evoliz_status' => $row['evoliz_status']])] : []),
            ], null, "A{$line}", true);
            $line++;
        }

        $last = $line - 1;
        if ($last > 1) {
            $sheet->getStyle("A2:A{$last}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            $sheet->getStyle("B2:B{$last}")->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
            $sheet->getStyle("E2:E{$last}")->getAlignment()->setWrapText(true);
            $sheet->getStyle("I2:I{$last}")->getAlignment()->setWrapText(true);
        }
        $sheet->setCellValue("A{$line}", 'TOTAL');
        $sheet->setCellValue("F{$line}", $last > 1 ? "=SUM(F2:F{$last})" : 0);

        ReportSheet::finish($sheet, count($headers), $line, $evoliz ? [...self::WIDTHS, 22] : self::WIDTHS);

        $period = $from->format('d/m/Y').' – '.$to->format('d/m/Y');
        $kinds = implode(', ', array_map(fn (WriteOffType $type) => $type->label(), $types));
        $sheet->getHeaderFooter()->setOddHeader('&L&B'.self::title().'&B'.' · '.$period.'&R'.$kinds)->setOddFooter('&RPage &P / &N');
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(1, 1);

        return $book;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<WriteOffType>  $types
     */
    public function write(array $rows, Carbon $from, Carbon $to, array $types, string $target): void
    {
        $writer = new Xlsx($this->spreadsheet($rows, $from, $to, $types));
        $writer->setPreCalculateFormulas(false);
        $writer->save($target);
    }
}
