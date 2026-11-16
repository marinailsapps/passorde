<?php

namespace App\Domain\Catalog;

use Illuminate\Support\Str;

/**
 * The installation's product categories, their groups (the stock report's tabs)
 * and the variants of a product, from `passorde.catalog` (config/passorde.php and
 * the private profile). Values are what the database stores (article_attributes,
 * article_requests, category_thresholds): a profile keeps them once data uses them,
 * and `passorde:check-profile` reports stored values it no longer lists.
 *
 * What a variant does follows its settings, never its name:
 *   - counts: an article of it without a category needs review, a new article
 *     and the bulk category may have it, the classifier and the new article
 *     default to the first one, the new article's classification comes from the
 *     articles of counting variants, and only its quantities count in the sales
 *     report (Ventes; a variant that does not count groups as "other" there);
 *   - group: its articles have their own tab in the stock report and no category,
 *     and group apart in the sales report (~material);
 *   - neither: its articles stay out of the stock report (e.g. shipping costs).
 * The first counting variant is the one an article gets when nothing says otherwise.
 */
final class Catalog
{
    /**
     * @return list<array{value: string, label: string, group: string}>
     */
    public static function categories(): array
    {
        return array_values((array) config('passorde.catalog.categories', []));
    }

    /**
     * @return list<string>
     */
    public static function categoryValues(): array
    {
        return array_column(self::categories(), 'value');
    }

    public static function isCategory(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::categoryValues(), true);
    }

    public static function categoryLabel(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        foreach (self::categories() as $category) {
            if ($category['value'] === $value) {
                return $category['label'];
            }
        }

        return $value;
    }

    /** The group key of a category; null for a value the profile does not list. */
    public static function categoryGroup(string $value): ?string
    {
        foreach (self::categories() as $category) {
            if ($category['value'] === $value) {
                return $category['group'];
            }
        }

        return null;
    }

    /**
     * The category a CSV cell or a typed value names: its value, whatever the case and accents.
     */
    public static function findCategory(string $text): ?string
    {
        return self::find(self::categoryValues(), $text);
    }

    /**
     * What a category is called, e.g. "catégorie" / "catégories" (catalog.category_name).
     */
    public static function categoryName(bool $plural = false): string
    {
        return (string) config('passorde.catalog.category_name.'.($plural ? 'plural' : 'singular'));
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function groups(): array
    {
        return array_values((array) config('passorde.catalog.category_groups', []));
    }

    public static function groupLabel(string $key): string
    {
        foreach (self::groups() as $group) {
            if ($group['key'] === $key) {
                return $group['label'];
            }
        }

        return $key;
    }

    /**
     * Groups that a variant owns (their articles have no category), in the order of category_groups.
     *
     * @return list<string>
     */
    public static function variantGroups(): array
    {
        $owned = array_filter(array_column(self::variants(), 'group'));

        return array_values(array_filter(array_column(self::groups(), 'key'), fn (string $key) => in_array($key, $owned, true)));
    }

    /**
     * @return list<array{value: string, label: string, name: string, counts: bool, group: string|null}>
     */
    public static function variants(): array
    {
        return array_map(fn (array $variant) => [
            'value' => $variant['value'],
            'label' => $variant['label'],
            'name' => $variant['name'] ?? $variant['label'],
            'counts' => (bool) ($variant['counts'] ?? false),
            'group' => $variant['group'] ?? null,
        ], array_values((array) config('passorde.catalog.variants', [])));
    }

    /**
     * @return list<string>
     */
    public static function variantValues(): array
    {
        return array_column(self::variants(), 'value');
    }

    /**
     * @return array{value: string, label: string, name: string, counts: bool, group: string|null}|null
     */
    public static function variant(?string $value): ?array
    {
        foreach (self::variants() as $variant) {
            if ($variant['value'] === $value) {
                return $variant;
            }
        }

        return null;
    }

    public static function isVariant(mixed $value): bool
    {
        return is_string($value) && self::variant($value) !== null;
    }

    /** The short label of a variant (reports, exports); the stored value for one the profile does not list. */
    public static function variantLabel(?string $value): ?string
    {
        return $value === null ? null : (self::variant($value)['label'] ?? $value);
    }

    /**
     * The variant a CSV cell names: its value, whatever the case and accents.
     */
    public static function findVariant(string $text): ?string
    {
        return self::find(self::variantValues(), $text);
    }

    /**
     * A counting variant: an article of it without a category needs review, a new article may have it,
     * the bulk category applies to it, and the sales report counts its quantities.
     */
    public static function counts(?string $variant): bool
    {
        return self::variant($variant)['counts'] ?? false;
    }

    /**
     * @return list<string>
     */
    public static function countingVariants(): array
    {
        return array_column(array_filter(self::variants(), fn (array $variant) => $variant['counts']), 'value');
    }

    /** The variant of an article nothing says more about: the first counting one. */
    public static function defaultVariant(): string
    {
        return self::countingVariants()[0] ?? self::variantValues()[0] ?? '';
    }

    /** The stock report tab a variant gives its articles (and then they have no category); null: their category's. */
    public static function variantGroup(?string $variant): ?string
    {
        return self::variant($variant)['group'] ?? null;
    }

    /** Variants whose articles the stock report leaves out: not counted and without a tab of their own. */
    public static function leftOutOfStock(?string $variant): bool
    {
        $found = self::variant($variant);

        return $found !== null && ! $found['counts'] && $found['group'] === null;
    }

    /**
     * Whether an article of this variant and category needs someone to choose its category.
     */
    public static function needsReview(?string $category, ?string $variant): bool
    {
        return $category === null && self::counts($variant) && self::categories() !== [];
    }

    /**
     * @param  list<string>  $values
     */
    private static function find(array $values, string $text): ?string
    {
        $wanted = self::key($text);

        foreach ($values as $value) {
            if (self::key($value) === $wanted) {
                return $value;
            }
        }

        return null;
    }

    private static function key(string $text): string
    {
        return Str::upper(Str::ascii($text));
    }
}
