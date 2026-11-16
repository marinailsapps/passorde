<?php

namespace App\Domain\Catalog;

use App\Domain\Reports\ReportSheet;
use App\Support\Profile\Brand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Catalogue → Excel: the price list handed to clients. Active articles
 * only, by reference, with prices (HT, VAT, TTC as a formula), category and
 * variant (labels from the profile); no stock or Evoliz internals. Numbers are numbers; the header has
 * filters.
 */
class CatalogExport
{
    public const TITLE = 'Tarifs';

    /** The category column is headed by what the profile calls a category. */
    private const HEADERS = ['Référence', 'Désignation', 'PU HT', 'TVA %', 'PU TTC', ':category', 'Type'];

    private const WIDTHS = [22, 60, 10, 7, 10, 16, 11];

    /**
     * @return list<object>
     */
    public function rows(): array
    {
        return DB::table('articles')
            ->leftJoin('article_attributes', 'article_attributes.article_id', '=', 'articles.id')
            ->where('articles.is_active', true)
            ->orderBy('articles.reference')
            ->get(['articles.reference', 'articles.designation', 'articles.unit_price_ht', 'articles.vat_rate', 'article_attributes.category', 'article_attributes.variant'])
            ->all();
    }

    public function spreadsheet(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setTitle(self::TITLE)->setCreator(Brand::name());
        $sheet = $book->getActiveSheet()->setTitle(self::TITLE);
        $headers = array_map(fn (string $header) => $header === ':category' ? Str::ucfirst(Catalog::categoryName()) : $header, self::HEADERS);
        $sheet->fromArray($headers);

        $line = 2;
        foreach ($this->rows() as $row) {
            $sheet->fromArray([
                $row->reference,
                $row->designation,
                ReportSheet::number($row->unit_price_ht),
                ReportSheet::number($row->vat_rate),
                "=ROUND(C{$line}*(1+D{$line}/100),2)",
                Catalog::categoryLabel($row->category),
                Catalog::variantLabel($row->variant),
            ], null, "A{$line}", true);
            $line++;
        }

        $last = $line - 1;
        if ($last > 1) {
            $sheet->getStyle("C2:C{$last}")->getNumberFormat()->setFormatCode('#,##0.00 €');
            $sheet->getStyle("E2:E{$last}")->getNumberFormat()->setFormatCode('#,##0.00 €');
            $sheet->getStyle("B2:B{$last}")->getAlignment()->setWrapText(true);
        }
        $sheet->setCellValue("A{$line}", ($last - 1).' articles');

        ReportSheet::finish($sheet, count($headers), $line, self::WIDTHS);
        $sheet->getHeaderFooter()->setOddHeader('&L&B'.self::TITLE.'&B · '.now('Europe/Paris')->format('d/m/Y H:i'))->setOddFooter('&RPage &P / &N');
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(1, 1);

        return $book;
    }

    public function write(string $target): void
    {
        $writer = new Xlsx($this->spreadsheet());
        $writer->setPreCalculateFormulas(false);
        $writer->save($target);
    }
}
