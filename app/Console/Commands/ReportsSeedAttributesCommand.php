<?php

namespace App\Console\Commands;

use App\Domain\Catalog\ArticleAttributes;
use App\Domain\Catalog\ArticleClassifier;
use App\Domain\Catalog\Catalog;
use App\Enums\AttributeSource;
use App\Models\Article;
use App\Models\ArticleAttribute;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One-off (docs/RUNBOOK.md "Reports: stock by category"): classifies every article,
 * then takes category and variant from a workbook (xlsx) where the reference
 * matches (case and spaces ignored). Every sheet whose header row names the
 * reference and category columns is read, the first ones first. Values set by
 * an admin are never touched; running it again gives the same result.
 */
class ReportsSeedAttributesCommand extends Command
{
    protected $signature = 'reports:seed-attributes
        {--file= : The workbook (xlsx) with a category per reference}
        {--reference-column=Référence : Header of the reference column}
        {--category-column=Catégorie : Header of the category column}
        {--variant-column=Variante : Header of the variant column (optional in a sheet)}';

    protected $description = 'Classify the articles (category, variant) and apply a workbook of categories';

    /** @var list<string> The headers read: reference, category, variant. */
    private array $columns = [];

    public function handle(ArticleAttributes $attributes): int
    {
        $path = (string) $this->option('file');
        $this->columns = [(string) $this->option('reference-column'), (string) $this->option('category-column'), (string) $this->option('variant-column')];
        if (! is_file($path)) {
            $this->error("No file at {$path}.");

            return self::FAILURE;
        }

        // The file is large (formatted rows down to 1,048,576, formulas): read the stored values only.
        ini_set('memory_limit', '1G');

        $articles = Article::query()->where('is_active', true)->get();
        foreach ($articles as $article) {
            $attributes->classify($article);
        }

        $fromFile = $this->readFile($path);
        $byReference = $articles->keyBy(fn (Article $article) => self::key($article->reference));
        $applied = 0;
        $unknown = 0;

        foreach ($fromFile as $key => [$category, $type]) {
            $article = $byReference->get($key);
            if ($article === null) {
                $unknown++;

                continue;
            }

            $attribute = ArticleAttribute::query()->where('article_id', $article->id)->firstOrFail();
            if ($attribute->source === AttributeSource::Manual) {
                continue;
            }

            $attribute->fill([
                'category' => $category,
                'variant' => $type,
                'needs_review' => Catalog::variantGroup($type) === null && $category === null,
                'source' => AttributeSource::File,
            ])->save();
            $applied++;
        }

        $counts = ArticleAttribute::query()->whereIn('article_id', $articles->pluck('id'))->toBase()
            ->selectRaw('source, count(*) as total, count(*) filter (where needs_review) as review')
            ->groupBy('source')->get()->keyBy('source');
        $review = (int) $counts->sum('review');

        $this->info(sprintf('%d active articles: %d automatic, %d from the file, %d set by hand; %d to review ("à vérifier").',
            $articles->count(),
            (int) ($counts[AttributeSource::Auto->value]->total ?? 0),
            (int) ($counts[AttributeSource::File->value]->total ?? 0),
            (int) ($counts[AttributeSource::Manual->value]->total ?? 0),
            $review,
        ));
        $this->line(sprintf('File: %d references read, %d applied, %d not in the active catalogue.', count($fromFile), $applied, $unknown));

        return self::SUCCESS;
    }

    /**
     * Reference key → [category, type] from the master sheet, then the other tabs.
     *
     * @return array<string, array{0: string|null, 1: string}>
     */
    private function readFile(string $path): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);

        $found = [];
        foreach ($book->getWorksheetIterator() as $sheet) {
            foreach ($this->rows($sheet) as [$reference, $category, $type]) {
                $key = self::key($reference);
                // A row without a category says nothing: the classifier's guess stays.
                if ($key === '' || trim($category) === '' || isset($found[$key])) {
                    continue;
                }
                $found[$key] = self::interpret($category, $type);
            }
        }

        return $found;
    }

    /**
     * [reference, category, variant] of the sheets that have the reference and category columns.
     *
     * @return \Generator<int, array{0: string, 1: string, 2: string}>
     */
    private function rows(Worksheet $sheet): \Generator
    {
        $columns = null;
        $empty = 0;

        foreach ($sheet->getRowIterator() as $row) {
            $values = [];
            foreach ($row->getCellIterator('A', 'P') as $cell) {
                $values[] = trim((string) self::value($cell));
            }

            if (implode('', $values) === '') {
                // Formatting runs down to row 1,048,576: stop after a long blank stretch.
                if (++$empty > 50) {
                    return;
                }

                continue;
            }
            $empty = 0;

            if ($columns === null) {
                $columns = array_map(fn (string $name) => array_search($name, $values, true), $this->columns);
                if ($columns[0] === false || $columns[1] === false) {
                    return; // A sheet without a category column (other lists of the workbook).
                }

                continue;
            }

            yield [$values[$columns[0]], $values[$columns[1]], $columns[2] === false ? '' : $values[$columns[2]]];
        }
    }

    private static function value(Cell $cell): mixed
    {
        $value = $cell->getValue();

        return is_string($value) && str_starts_with($value, '=') ? $cell->getOldCalculatedValue() : $value;
    }

    /**
     * A category cell naming the tab of a variant that has one (its group's label)
     * gives that variant. Any other spelling goes through the classifier; one it
     * does not know is a category the file named that the profile lacks: the
     * profile's last category, its catch-all. The type cell gives the counting
     * variant whose label starts with the same three letters, else the default one.
     *
     * @return array{0: string|null, 1: string}
     */
    private static function interpret(string $named, string $type): array
    {
        $named = mb_strtoupper($named);
        foreach (Catalog::variants() as $variant) {
            if ($variant['group'] !== null && Catalog::groupLabel($variant['group']) === $named) {
                return [null, $variant['value']];
            }
        }

        $categories = Catalog::categoryValues();
        $category = ArticleClassifier::classify($named, '')['category'] ?? ($categories === [] ? null : $categories[array_key_last($categories)]);
        $prefix = mb_substr(Str::upper(Str::ascii($type)), 0, 3);
        $counted = null;
        foreach (Catalog::countingVariants() as $value) {
            if (strlen($prefix) === 3 && str_starts_with(Str::upper(Str::ascii((string) Catalog::variantLabel($value))), $prefix)) {
                $counted = $value;
                break;
            }
        }

        return [$category, $counted ?? Catalog::defaultVariant()];
    }

    /** Reference comparison key: case, accents, HTML entities and spaces ignored. */
    public static function key(?string $reference): string
    {
        return implode('', ArticleClassifier::tokens((string) $reference));
    }
}
