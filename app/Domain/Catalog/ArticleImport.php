<?php

namespace App\Domain\Catalog;

use App\Domain\Import\CsvReader;
use App\Domain\Import\ImportReport;
use App\Domain\Import\RowRejected;
use App\Enums\AttributeSource;
use App\Models\Article;
use App\Models\User;

/**
 * The catalogue from a CSV file, in file mode.
 * Key: reference, among rows with no Evoliz id. An empty cell never erases, rows
 * absent from the file are left alone, and only real changes save, so that a
 * re-import leaves updated_at and the phones' delta alone.
 */
final class ArticleImport
{
    /** The columns before the category and variant ones (named by the profile: columns()), and after them. */
    public const BASE_COLUMNS = ['reference', 'designation', 'prix_ht', 'tva', 'stock', 'gestion_stock', 'actif', 'fournisseur', 'classification'];

    public const LAST_COLUMNS = ['seuil'];

    /** The neutral names of the category and variant columns, also accepted when the profile names them otherwise. */
    public const CATEGORY_COLUMN = 'categorie';

    public const VARIANT_COLUMN = 'variante';

    /**
     * The Evoliz article list's CSV export. The classifier reads the classification's
     * code, as after a sync, so the label only serves a file without the code. "Nature"
     * (Produit/Service) says nothing about `type`. Every other column is ignored.
     */
    public const ALIASES = [
        'Référence' => 'reference',
        'Désignation' => 'designation',
        'PU HT' => 'prix_ht',
        'TVA' => 'tva',
        'Qté stockée' => 'stock',
        'Article stocké' => 'gestion_stock',
        'Désactivé' => 'desactive',
        'Fournisseur' => 'fournisseur',
        'Code Classification vente' => 'classification',
        'Classification vente' => 'classification_vente',
    ];

    /** The order form refuses a higher unit price. */
    private const MAX_PRICE = 99999;

    private const MAX_STOCK = 9999999;

    private const MAX_THRESHOLD = 1000000;

    /** Template column of each article column, for the preview (category and variant: columnOf()). */
    private const COLUMN_OF = [
        'designation' => 'designation',
        'unit_price_ht' => 'prix_ht',
        'vat_rate' => 'tva',
        'stock_qty' => 'stock',
        'is_stocked' => 'gestion_stock',
        'is_active' => 'actif',
        'supplier_name' => 'fournisseur',
        'sale_classification_code' => 'classification',
        'reorder_threshold' => 'seuil',
    ];

    public function __construct(private readonly ArticleAttributes $attributes) {}

    /**
     * The template's columns: the category and variant ones as catalog.csv names them.
     *
     * @return list<string>
     */
    public static function columns(): array
    {
        return [...self::BASE_COLUMNS, self::categoryColumn(), self::variantColumn(), ...self::LAST_COLUMNS];
    }

    public static function categoryColumn(): string
    {
        return (string) config('passorde.catalog.csv.category', self::CATEGORY_COLUMN);
    }

    public static function variantColumn(): string
    {
        return (string) config('passorde.catalog.csv.variant', self::VARIANT_COLUMN);
    }

    /**
     * Other software's names for a column, and the neutral category and variant names when the profile uses others.
     *
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            ...self::ALIASES,
            ...array_filter([self::CATEGORY_COLUMN => self::categoryColumn(), self::VARIANT_COLUMN => self::variantColumn()], fn (string $column, string $alias) => $column !== $alias, ARRAY_FILTER_USE_BOTH),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function columnOf(): array
    {
        return [...self::COLUMN_OF, 'category' => self::categoryColumn(), 'variant' => self::variantColumn()];
    }

    /**
     * A fictional row for the downloadable template: catalog.import_example, else
     * built from the profile's first category and its default variant.
     *
     * @return list<string>
     */
    public static function templateRow(): array
    {
        $example = (array) config('passorde.catalog.import_example') + [
            'reference' => 'EXEMPLE',
            'designation' => 'ARTICLE EXEMPLE',
            'vat' => 20,
            'category' => Catalog::categoryValues()[0] ?? null,
            'variant' => Catalog::defaultVariant(),
        ];

        return [
            (string) $example['reference'], (string) $example['designation'], '25,00', str_replace('.', ',', (string) $example['vat']), '10', 'oui', 'oui',
            'Fournisseur Exemple', '', (string) ($example['category'] ?? ''), (string) $example['variant'], '5',
        ];
    }

    public function run(string $contents, User $user, bool $dryRun): ImportReport
    {
        $csv = CsvReader::read($contents, self::columns(), ['reference'], self::aliases());
        $existing = Article::query()->whereNull('evoliz_article_id')->get()->keyBy(fn (Article $article) => ImportReport::key($article->reference));

        return ImportReport::run('articles', 'reference', $csv, $user, $dryRun, function (int $line, string $key, array $cells, ImportReport $report) use ($existing, $user) {
            $warnings = [];
            $values = self::values($cells, $warnings);
            $stated = self::statedAttributes($cells, $warnings);
            $article = $existing->get(ImportReport::key($key));
            $creating = $article === null;

            if ($article === null) {
                $missing = array_diff(['designation' => 'designation', 'unit_price_ht' => 'prix_ht', 'vat_rate' => 'tva'], array_intersect_key(self::columnOf(), $values));

                if ($missing !== []) {
                    throw new RowRejected(__('New article: :columns required.', ['columns' => implode(', ', $missing)]));
                }

                $article = new Article([
                    'reference' => $key,
                    'is_active' => true,
                    'is_stocked' => array_key_exists('stock_qty', $values),
                    'stock_qty' => null,
                ]);
            }

            $article->fill($values);
            $fields = array_values(array_intersect_key(self::columnOf(), $article->getDirty()));
            $changed = $article->isDirty();
            $article->save();

            $attributeFields = $this->saveAttributes($article, $stated, $user, $changed);
            $fields = array_values(array_unique([...$fields, ...$attributeFields]));

            $action = match (true) {
                $creating => ImportReport::CREATED,
                $changed => ImportReport::UPDATED,
                default => ImportReport::UNCHANGED,
            };

            $report->add($line, $key, $action, $creating ? [] : $fields, $warnings);
        });
    }

    /**
     * The article columns the row states.
     *
     * @param  array<string, string>  $cells
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private static function values(array $cells, array &$warnings): array
    {
        $values = [];

        if (($cells['classification'] ?? '') === '') {
            $cells['classification'] = $cells['classification_vente'] ?? '';
        }

        foreach (['designation' => 'designation', 'fournisseur' => 'supplier_name', 'classification' => 'sale_classification_code'] as $column => $attribute) {
            $cell = $cells[$column] ?? '';

            if ($cell !== '') {
                // Designations are text columns; 255 is what the others hold.
                if ($column !== 'designation' && mb_strlen($cell) > 255) {
                    throw new RowRejected(__('Column ":column" is too long.', ['column' => $column]));
                }

                $values[$attribute] = $cell;
            }
        }

        if (($cells['prix_ht'] ?? '') !== '') {
            $values['unit_price_ht'] = self::number($cells['prix_ht'], 'prix_ht', 0, self::MAX_PRICE);
        }

        if (($cells['tva'] ?? '') !== '') {
            $values['vat_rate'] = self::number($cells['tva'], 'tva', 0, 100);

            // The installation's usual rates (profile catalog.usual_vat); none listed: no warning.
            $usual = array_map(floatval(...), (array) config('passorde.catalog.usual_vat'));
            if ($usual !== [] && ! in_array((float) $values['vat_rate'], $usual, true)) {
                $warnings[] = (string) __('Unusual VAT rate: :rate %.', ['rate' => $cells['tva']]);
            }
        }

        if (($cells['stock'] ?? '') !== '') {
            $values['stock_qty'] = self::number($cells['stock'], 'stock', -self::MAX_STOCK, self::MAX_STOCK);
        }

        if (($cells['gestion_stock'] ?? '') !== '') {
            $values['is_stocked'] = CsvReader::yesNo($cells['gestion_stock']) ?? throw new RowRejected(__('Column ":column": oui or non expected.', ['column' => 'gestion_stock']));
        }

        $active = CsvReader::active($cells);

        if ($active !== null) {
            $values['is_active'] = $active;
        }

        return $values;
    }

    private static function number(string $cell, string $column, int $min, int $max): string
    {
        $number = CsvReader::decimal($cell);

        if ($number === null || (float) $number < $min || (float) $number > $max) {
            throw new RowRejected(__('Column ":column": ":value" is not a valid number.', ['column' => $column, 'value' => $cell]));
        }

        return number_format((float) $number, 2, '.', '');
    }

    /**
     * What the row says about the article's attributes (category and variant as the
     * profile lists them, whatever the case and accents). An unknown one only warns:
     * the classifier decides.
     *
     * @param  array<string, string>  $cells
     * @param  list<string>  $warnings
     * @return array{category?: string, variant?: string, reorder_threshold?: int}
     */
    private static function statedAttributes(array $cells, array &$warnings): array
    {
        $stated = [];

        $categoryColumn = self::categoryColumn();
        $variantColumn = self::variantColumn();

        if (($cells[$categoryColumn] ?? '') !== '') {
            $category = Catalog::findCategory($cells[$categoryColumn]);

            if ($category !== null) {
                $stated['category'] = $category;
            } else {
                $warnings[] = (string) __('Unknown category ":value": ignored.', ['value' => $cells[$categoryColumn]]);
            }
        }

        if (($cells[$variantColumn] ?? '') !== '') {
            $type = Catalog::findVariant($cells[$variantColumn]);

            if ($type !== null) {
                $stated['variant'] = $type;
            } else {
                $warnings[] = (string) __('Unknown type ":value": ignored.', ['value' => $cells[$variantColumn]]);
            }
        }

        if (($cells['seuil'] ?? '') !== '') {
            if (! ctype_digit($cells['seuil']) || (int) $cells['seuil'] > self::MAX_THRESHOLD) {
                throw new RowRejected(__('Column ":column": ":value" is not a valid number.', ['column' => 'seuil', 'value' => $cells['seuil']]));
            }

            $stated['reorder_threshold'] = (int) $cells['seuil'];
        }

        return $stated;
    }

    /**
     * Without a stated category or variant the classifier guesses, as after a sync, and
     * keeps its hands off values set by hand. What the file states wins and is marked so.
     *
     * @param  array{category?: string, variant?: string, reorder_threshold?: int}  $stated
     * @return list<string> Template columns that changed
     */
    private function saveAttributes(Article $article, array $stated, User $user, bool &$changed): array
    {
        $attribute = $this->attributes->classify($article);
        $classified = $attribute->wasRecentlyCreated ? [] : array_keys($attribute->getChanges());
        $changed = $changed || $attribute->wasRecentlyCreated || $classified !== [];

        if (isset($stated['category']) || isset($stated['variant'])) {
            $category = $stated['category'] ?? $attribute->category;
            $type = $stated['variant'] ?? $attribute->variant;

            $attribute->fill([
                'category' => $category,
                'variant' => $type,
                'needs_review' => Catalog::needsReview($category, $type),
                'source' => AttributeSource::File,
            ]);
        }

        if (isset($stated['reorder_threshold'])) {
            $attribute->reorder_threshold = $stated['reorder_threshold'];
        }

        $dirty = array_keys($attribute->getDirty());

        if ($dirty !== []) {
            $attribute->updated_by = $user->id;
            $attribute->save();
            $changed = true;
        }

        return array_values(array_intersect_key(self::columnOf(), array_flip([...$classified, ...$dirty])));
    }
}
