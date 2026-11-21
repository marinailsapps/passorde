<?php

namespace App\Support\Profile;

use App\Domain\Catalog\ArticleImport;
use App\Domain\Clients\ClientImport;
use App\Domain\Reports\StockReport;
use App\Support\EvolizState;
use Database\Seeders\PaytypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What is wrong in an installation profile (`php artisan passorde:check-profile`):
 * keys config/passorde.php does not know, required entries missing or of the
 * wrong kind, and values stored in the database that the profile does not list
 * (an article whose category the profile forgets would turn "unknown").
 *
 * Errors make the profile unusable for this database; warnings are worth a look.
 */
final class ProfileCheck
{
    /**
     * The keys a profile may set. A list of names: leaf keys; name => shape: a nested section;
     * name => [[…]]: a list of entries with these keys; '*': any keys (a map the check reads itself).
     */
    public const SHAPE = [
        'brand' => ['name', 'short_name', 'logo', 'theme_color', 'accent', 'accent_strong', 'accent_soft'],
        'phone' => ['storage_prefix'],
        'messages' => ['sms', 'sms_with_bl'],
        'units' => ['singular', 'plural'],
        'free_lines' => ['enabled', 'label', 'evoliz_suffix'],
        'client' => ['extra_field' => ['label', 'csv', 'searchable']],
        'catalog' => [
            'categories' => [['value', 'label', 'group']],
            'category_name' => ['singular', 'plural'],
            'category_groups' => [['key', 'label']],
            'variants' => [['value', 'label', 'name', 'counts', 'group']],
            'csv' => ['category', 'variant'],
            'import_example' => ['reference', 'designation', 'vat', 'category', 'variant'],
            'classifier' => [
                'words' => '*', 'aliases' => '*', 'weak_aliases' => '*', 'phrases' => '*', 'secondary' => '*', 'variant_words' => '*',
                'forced_variant_words' => '*', 'classification_codes' => '*', 'weak_classification_codes' => '*',
            ],
            'new_article' => ['label', 'vat', 'reference', 'designation', 'complete_pattern', 'variant_codes' => '*'],
            'usual_vat' => '*',
        ],
        'evoliz' => ['payterm_id', 'recovery_indemnity', 'delivery_comment', 'enabled_paytypes' => '*'],
        'reports' => [
            'stock_title', 'write_offs_title',
            'stock_tabs' => ['all', 'unclassified', 'broken', 'gifts', 'material_gifts'],
            'write_off_labels' => ['broken', 'order_gift', 'gift'],
        ],
        'texts' => '*',
        'aliases' => [
            'client' => '*', 'article' => '*', 'totals' => '*', 'summary' => '*', 'stock_tabs' => '*',
            'routes' => ['api' => '*', 'web' => '*'], 'texts' => '*', 'groupings' => '*',
            'schema' => ['tables' => '*', 'columns' => '*'],
        ],
    ];

    /** Widths of the columns the values are stored in (migrations). */
    private const CATEGORY_MAX = 30;

    private const VARIANT_MAX = 10;

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param  bool  $required  The directory was named (--path, PASSORDE_PROFILE): it must hold a profile.php
     * @param  array<string, mixed>|null  $config  The settings to check instead of the directory's (the running ones)
     * @return array{errors: list<string>, warnings: list<string>, loaded: bool}
     */
    public static function run(?string $directory, bool $database = true, bool $required = false, ?array $config = null): array
    {
        $check = new self;

        try {
            $raw = Profile::read($directory, $required);
        } catch (Throwable $e) {
            return ['errors' => ['profile.php could not be read: '.$e->getMessage()], 'warnings' => [], 'loaded' => false];
        }

        $config ??= Profile::load($directory, $required);

        if ($raw !== null) {
            $check->unknownKeys($raw, self::SHAPE, '');
        }
        $check->values($config);
        $check->build($config);

        // A database without any table (a new installation before its first deploy) holds nothing to compare:
        // said, so that the deploy log shows it. Any table at all, and the comparison runs.
        if ($database && Schema::getTables() === []) {
            $check->warnings[] = 'The database is empty (no table yet): stored values not compared.';
        } elseif ($database) {
            $check->database($config);
            $check->phones($raw);
        }

        return ['errors' => $check->errors, 'warnings' => $check->warnings, 'loaded' => $raw !== null];
    }

    /**
     * The errors of the profile this installation runs on, against its database (ops:check profile, the daily digest).
     *
     * @return list<string>
     */
    public static function installationErrors(): array
    {
        return self::run(config('passorde.profile.directory'), true, (bool) config('passorde.profile.required'), (array) config('passorde'))['errors'];
    }

    /**
     * @param  array<mixed>|string  $shape
     */
    private function unknownKeys(mixed $value, array|string $shape, string $path): void
    {
        if ($shape === '*' || ! is_array($value)) {
            return;
        }

        if (array_keys($shape) === [0] && is_array($shape[0])) {
            foreach (array_is_list($value) ? $value : [] as $i => $entry) {
                $this->unknownKeys($entry, $shape[0], "{$path}[{$i}]");
            }

            return;
        }

        $leaves = array_filter($shape, fn ($item, $key) => is_int($key), ARRAY_FILTER_USE_BOTH);

        foreach ($value as $key => $item) {
            $name = ltrim("{$path}.{$key}", '.');

            if (is_string($key) && array_key_exists($key, $shape)) {
                $this->unknownKeys($item, $shape[$key], $name);
            } elseif (! in_array($key, $leaves, true)) {
                $this->errors[] = $path === ''
                    ? "Unknown key \"{$key}\": a profile sets only ".implode(', ', array_keys(self::SHAPE)).'.'
                    : "Unknown key \"{$name}\".";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function values(array $config): void
    {
        // Name, colours, logo and the phones' storage prefix: what the asset build needs (Brand).
        array_push($this->errors, ...Brand::buildErrors($config));

        foreach (['messages.sms', 'messages.sms_with_bl', 'units.singular', 'units.plural',
            'reports.stock_title', 'reports.write_offs_title', 'catalog.category_name.singular', 'catalog.category_name.plural'] as $key) {
            $this->text($key, data_get($config, $key));
        }
        foreach (self::SHAPE['reports']['stock_tabs'] as $key) {
            $this->text("reports.stock_tabs.{$key}", data_get($config, "reports.stock_tabs.{$key}"));
        }
        foreach (self::SHAPE['reports']['write_off_labels'] as $key) {
            $this->text("reports.write_off_labels.{$key}", data_get($config, "reports.write_off_labels.{$key}"));
        }

        foreach (['sms' => [':number'], 'sms_with_bl' => [':number', ':bl']] as $key => $placeholders) {
            foreach ($placeholders as $placeholder) {
                if (is_string(data_get($config, "messages.{$key}")) && ! str_contains((string) data_get($config, "messages.{$key}"), $placeholder)) {
                    $this->warnings[] = "messages.{$key} does not show {$placeholder}.";
                }
            }
        }

        if (! is_bool(data_get($config, 'free_lines.enabled'))) {
            $this->errors[] = 'free_lines.enabled must be true or false.';
        }
        if (data_get($config, 'free_lines.enabled') === true) {
            $this->text('free_lines.label', data_get($config, 'free_lines.label'));
        }
        if (! is_string(data_get($config, 'free_lines.evoliz_suffix'))) {
            $this->errors[] = 'free_lines.evoliz_suffix must be a text (it may be empty).';
        }

        $this->extraField(data_get($config, 'client.extra_field'));

        [$categories, $variants] = $this->catalog((array) data_get($config, 'catalog'));
        $this->classifier(data_get($config, 'catalog.classifier'), $categories, $variants);
        $this->newArticle(data_get($config, 'catalog.new_article'), $variants);
        $this->importExample(data_get($config, 'catalog.import_example'), $categories, $variants);
        $this->csvColumns((array) data_get($config, 'catalog.csv'));

        $vat = data_get($config, 'catalog.usual_vat');
        if (! is_array($vat) || ! array_is_list($vat) || array_filter($vat, fn ($rate) => ! is_int($rate) && ! is_float($rate)) !== []) {
            $this->errors[] = 'catalog.usual_vat must be a list of rates, e.g. [5.5, 20].';
        }

        $this->evoliz((array) data_get($config, 'evoliz'));
        $this->texts(data_get($config, 'texts'));
    }

    /**
     * The assets in public/build against these settings (their stamp, Brand::built()): built for another storage
     * prefix, the phones would open empty databases and leave their unsent orders in the old ones.
     *
     * @param  array<string, mixed>  $config
     */
    private function build(array $config): void
    {
        $built = Brand::built();
        if ($built === null || Brand::buildErrors($config) !== []) {
            return;
        }

        $wanted = Brand::forBuild($config);
        if (($built['storage_prefix'] ?? null) !== $wanted['storage_prefix']) {
            $this->errors[] = sprintf('The assets in public/build were built for phone.storage_prefix "%s", this profile sets "%s": the phones would no longer find their data and unsent orders. Build the assets with this profile (docs/RUNBOOK.md "Installation profile").', $built['storage_prefix'] ?? '', $wanted['storage_prefix']);
        }

        // The older names the phones read their stored rows with (aliases): another set leaves rows unread.
        if (isset($built['aliases']) && $built['aliases'] !== json_encode($wanted['aliases'])) {
            $this->errors[] = 'The assets in public/build were built with other older names (aliases.client, article, totals or groupings): phones would read their stored data differently. Build the assets with this profile (docs/RUNBOOK.md "Installation profile").';
        }

        $differ = array_map(fn (string $key) => "brand.{$key}", array_keys(array_diff_assoc(array_diff_key($wanted, ['storage_prefix' => true, 'aliases' => true]), $built)));
        // A stamp without the logo's hash says nothing about the logo.
        if (isset($built['logo']) && $built['logo'] !== Brand::logoHash($config)) {
            $differ[] = 'brand.logo';
        }
        if ($differ !== []) {
            $this->warnings[] = 'The assets in public/build were built with another '.implode(', ', $differ).': the web manifest, icons or colours do not follow this profile until they are built with it.';
        }
    }

    /**
     * An installation with a profile and orders came to phones before phone.storage_prefix existed, maybe under
     * another prefix: its profile must say which (even the default), or a build could orphan unsent orders. Without
     * a profile (PASSORDE_PROFILE=none) there is nothing to say: a neutral installation never had other names.
     *
     * @param  array<mixed>|null  $raw  the profile as written
     */
    private function phones(?array $raw): void
    {
        if ($raw === null || (is_array($raw['phone'] ?? null) && array_key_exists('storage_prefix', $raw['phone']))) {
            return;
        }

        if (DB::table('orders')->exists()) {
            $this->errors[] = 'phone.storage_prefix is not set, and this installation already has orders: its phones may keep their data under the prefix of an older version. Set phone.storage_prefix in the profile to the prefix they use (docs/RUNBOOK.md "Installation profile"), or to "'.Brand::DEFAULT_STORAGE_PREFIX.'" if they never used another.';
        }
    }

    /**
     * The profile's French sentences: by an English key of lang/fr.json, as texts.
     */
    private function texts(mixed $texts): void
    {
        if (! is_array($texts)) {
            $this->errors[] = 'texts must be a list of English key => French text.';

            return;
        }

        $known = (array) json_decode((string) file_get_contents(lang_path('fr.json')), true);
        foreach ($texts as $key => $text) {
            if (! is_string($text) || trim($text) === '') {
                $this->errors[] = "texts \"{$key}\" must be a French text.";
            } elseif (isset(Wording::oldTextKeys()[$key])) {
                $this->warnings[] = sprintf('texts "%s" is an older name of "%s" (aliases.texts): still read as it, rename it in the profile.', $key, Wording::oldTextKeys()[$key]);
            } elseif (! array_key_exists($key, $known)) {
                $this->warnings[] = "texts \"{$key}\" is not a key of lang/fr.json: it is never shown.";
            }
        }
    }

    /**
     * @param  array<mixed>  $evoliz
     */
    private function evoliz(array $evoliz): void
    {
        $term = $evoliz['payterm_id'] ?? null;
        if ($term === null) {
            if (EvolizState::enabled()) {
                $this->warnings[] = 'evoliz.payterm_id is not set: with Evoliz on, approved orders fail until it is (an id of GET /payterms).';
            }
        } elseif (! is_int($term) || $term < 1) {
            $this->errors[] = 'evoliz.payterm_id must be an Evoliz payment term id (a whole number) or null.';
        }

        if (! is_bool($evoliz['recovery_indemnity'] ?? null)) {
            $this->errors[] = 'evoliz.recovery_indemnity must be true or false.';
        }

        $comment = $evoliz['delivery_comment'] ?? null;
        if ($comment !== null && (! is_string($comment) || trim($comment) === '')) {
            $this->errors[] = 'evoliz.delivery_comment must be a text or null.';
        } elseif (is_string($comment) && ! str_contains($comment, ':number')) {
            $this->warnings[] = 'evoliz.delivery_comment does not show :number.';
        }

        $paytypes = $evoliz['enabled_paytypes'] ?? null;
        if (! is_array($paytypes) || ! array_is_list($paytypes) || array_filter($paytypes, fn ($id) => ! is_int($id) || ! array_key_exists($id, PaytypeSeeder::PAYTYPES)) !== []) {
            $this->errors[] = 'evoliz.enabled_paytypes must be a list of Evoliz payment type ids (1 to '.count(PaytypeSeeder::PAYTYPES).').';
        }
    }

    private function extraField(mixed $field): void
    {
        if ($field === null) {
            return;
        }

        if (! is_array($field)) {
            $this->errors[] = 'client.extra_field must be null or [label, csv, searchable].';

            return;
        }

        $this->text('client.extra_field.label', $field['label'] ?? null);

        $csv = $field['csv'] ?? null;
        $taken = [...ClientImport::BASE_COLUMNS, ...array_values(ClientImport::ALIASES)];
        if (! is_string($csv) || preg_match('/^[a-z][a-z0-9_]*$/', $csv) !== 1) {
            $this->errors[] = 'client.extra_field.csv must be a CSV column name in lower case (letters, digits, _).';
        } elseif (in_array($csv, $taken, true)) {
            $this->errors[] = "client.extra_field.csv \"{$csv}\" is already a column of the clients CSV.";
        }

        if (! is_bool($field['searchable'] ?? null)) {
            $this->errors[] = 'client.extra_field.searchable must be true or false.';
        }
    }

    /**
     * @param  array<mixed>  $catalog
     * @return array{0: list<string>, 1: list<string>} category values, variant values
     */
    private function catalog(array $catalog): array
    {
        $groups = $this->entries('catalog.category_groups', $catalog['category_groups'] ?? null, 'key', function (array $group, string $at) {
            if (! is_string($group['key'] ?? null) || preg_match('/^[a-z0-9_]+$/', $group['key']) !== 1) {
                $this->errors[] = "{$at}.key must be lower case letters, digits and _.";
            } elseif (in_array($group['key'], StockReport::FIXED_TABS, true)) {
                $this->errors[] = "{$at}.key \"{$group['key']}\" is the key of a fixed stock tab: choose another.";
            }
            $this->text("{$at}.label", $group['label'] ?? null);
        });

        $used = [];
        $categories = $this->entries('catalog.categories', $catalog['categories'] ?? null, 'value', function (array $category, string $at) use ($groups, &$used) {
            $this->storedValue("{$at}.value", $category['value'] ?? null, self::CATEGORY_MAX);
            $this->text("{$at}.label", $category['label'] ?? null);
            $group = $category['group'] ?? null;
            if (! in_array($group, $groups, true)) {
                $this->errors[] = sprintf('%s.group "%s" is not a key of catalog.category_groups.', $at, is_scalar($group) ? $group : get_debug_type($group));
            }
            $used[] = $group;
        });

        $variants = $this->entries('catalog.variants', $catalog['variants'] ?? null, 'value', function (array $variant, string $at) use ($groups, &$used) {
            $this->storedValue("{$at}.value", $variant['value'] ?? null, self::VARIANT_MAX);
            $this->text("{$at}.label", $variant['label'] ?? null);
            if (array_key_exists('name', $variant)) {
                $this->text("{$at}.name", $variant['name']);
            }
            if (! is_bool($variant['counts'] ?? null)) {
                $this->errors[] = "{$at}.counts must be true or false.";
            }
            $group = $variant['group'] ?? null;
            if ($group !== null && ! in_array($group, $groups, true)) {
                $this->errors[] = sprintf('%s.group "%s" is not a key of catalog.category_groups.', $at, is_scalar($group) ? $group : get_debug_type($group));
            }
            $used[] = $group;
        });

        if ($variants === []) {
            $this->errors[] = 'catalog.variants needs at least one variant.';
        }

        foreach (array_diff($groups, $used) as $group) {
            $this->warnings[] = "catalog.category_groups \"{$group}\" has no category and no variant: its tab stays empty.";
        }

        return [$categories, $variants];
    }

    /**
     * Checks a list of entries and returns their identifiers (duplicates reported).
     *
     * @param  callable(array<mixed>, string): void  $each
     * @return list<string>
     */
    private function entries(string $path, mixed $list, string $id, callable $each): array
    {
        if (! is_array($list) || ! array_is_list($list)) {
            $this->errors[] = "{$path} must be a list.";

            return [];
        }

        $ids = [];
        foreach ($list as $i => $entry) {
            if (! is_array($entry)) {
                $this->errors[] = "{$path}[{$i}] must be an entry with keys.";

                continue;
            }
            $each($entry, "{$path}[{$i}]");
            if (is_string($entry[$id] ?? null)) {
                if (in_array($entry[$id], $ids, true)) {
                    $this->errors[] = "{$path}: \"{$entry[$id]}\" is listed twice.";
                }
                $ids[] = $entry[$id];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<string>  $categories
     * @param  list<string>  $variants
     */
    private function classifier(mixed $classifier, array $categories, array $variants): void
    {
        if ($classifier === null) {
            return;
        }

        if (! is_array($classifier)) {
            $this->errors[] = 'catalog.classifier must be null or a set of word lists.';

            return;
        }

        foreach (['words', 'aliases', 'weak_aliases', 'phrases'] as $key) {
            foreach ((array) ($classifier[$key] ?? []) as $word => $category) {
                if (! in_array($category, $categories, true)) {
                    $this->errors[] = sprintf('catalog.classifier.%s "%s" names "%s", which is not in catalog.categories.', $key, $word, is_scalar($category) ? $category : get_debug_type($category));
                }
            }
        }

        foreach ((array) ($classifier['secondary'] ?? []) as $category) {
            if (! in_array($category, $categories, true)) {
                $this->errors[] = sprintf('catalog.classifier.secondary names "%s", which is not in catalog.categories.', is_scalar($category) ? $category : get_debug_type($category));
            }
        }

        foreach (['variant_words', 'forced_variant_words'] as $key) {
            foreach ((array) ($classifier[$key] ?? []) as $variant => $words) {
                if (! in_array($variant, $variants, true)) {
                    $this->errors[] = "catalog.classifier.{$key} \"{$variant}\" is not in catalog.variants.";
                }
                if (! is_array($words) || ! array_is_list($words)) {
                    $this->errors[] = "catalog.classifier.{$key} \"{$variant}\" must be a list of words (or of lists of words that must all appear).";
                }
            }
        }

        foreach (['classification_codes', 'weak_classification_codes'] as $key) {
            foreach ((array) ($classifier[$key] ?? []) as $code => $value) {
                if (! in_array($value, [...$categories, ...$variants], true)) {
                    $this->errors[] = sprintf('catalog.classifier.%s "%s" names "%s", neither a category nor a variant.', $key, $code, is_scalar($value) ? $value : get_debug_type($value));
                }
            }
        }
    }

    /**
     * @param  array<mixed>  $csv
     */
    private function csvColumns(array $csv): void
    {
        $taken = [...ArticleImport::BASE_COLUMNS, ...ArticleImport::LAST_COLUMNS, ...array_values(ArticleImport::ALIASES)];
        foreach (['category', 'variant'] as $key) {
            $column = $csv[$key] ?? null;
            if (! is_string($column) || preg_match('/^[a-z][a-z0-9_]*$/', $column) !== 1) {
                $this->errors[] = "catalog.csv.{$key} must be a CSV column name in lower case (letters, digits, _).";
            } elseif (in_array($column, $taken, true)) {
                $this->errors[] = "catalog.csv.{$key} \"{$column}\" is already a column of the articles CSV.";
            }
        }
        if (is_string($csv['category'] ?? null) && $csv['category'] === ($csv['variant'] ?? null)) {
            $this->errors[] = 'catalog.csv.category and catalog.csv.variant must differ.';
        }
    }

    /**
     * @param  list<string>  $categories
     * @param  list<string>  $variants
     */
    private function importExample(mixed $example, array $categories, array $variants): void
    {
        if ($example === null) {
            return;
        }

        if (! is_array($example)) {
            $this->errors[] = 'catalog.import_example must be null or [reference, designation, vat, category, variant].';

            return;
        }

        foreach (['reference', 'designation'] as $key) {
            $this->text("catalog.import_example.{$key}", $example[$key] ?? null);
        }
        if (! is_int($example['vat'] ?? null) && ! is_float($example['vat'] ?? null)) {
            $this->errors[] = 'catalog.import_example.vat must be a rate, e.g. 20.';
        }
        if (($example['category'] ?? null) !== null && ! in_array($example['category'], $categories, true)) {
            $this->errors[] = 'catalog.import_example.category is not in catalog.categories.';
        }
        if (! in_array($example['variant'] ?? null, $variants, true)) {
            $this->errors[] = 'catalog.import_example.variant is not in catalog.variants.';
        }
    }

    /**
     * @param  list<string>  $variants
     */
    private function newArticle(mixed $article, array $variants): void
    {
        if ($article === null) {
            return;
        }

        if (! is_array($article)) {
            $this->errors[] = 'catalog.new_article must be null or [label, vat, reference, designation].';

            return;
        }

        foreach (['label', 'reference', 'designation'] as $key) {
            $this->text("catalog.new_article.{$key}", $article[$key] ?? null);
        }
        if (! is_int($article['vat'] ?? null) && ! is_float($article['vat'] ?? null)) {
            $this->errors[] = 'catalog.new_article.vat must be a rate, e.g. 5.5.';
        }
        $pattern = $article['complete_pattern'] ?? null;
        if ($pattern !== null && (! is_string($pattern) || @preg_match($pattern, '') === false)) {
            $this->errors[] = 'catalog.new_article.complete_pattern must be a regular expression, e.g. "/\\bLOT\\b/i", or null.';
        }
        foreach ((array) ($article['variant_codes'] ?? []) as $variant => $codes) {
            if (! in_array($variant, $variants, true)) {
                $this->errors[] = "catalog.new_article.variant_codes \"{$variant}\" is not in catalog.variants.";
            }
        }
    }

    /**
     * Values stored in the database that the profile does not list, and data an option switched off would hide.
     *
     * @param  array<string, mixed>  $config
     */
    private function database(array $config): void
    {
        $categories = array_column((array) data_get($config, 'catalog.categories'), 'value');
        $variants = array_column((array) data_get($config, 'catalog.variants'), 'value');

        foreach ([['article_attributes', 'category', $categories, 'catalog.categories'], ['article_requests', 'category', $categories, 'catalog.categories'],
            ['category_thresholds', 'category', $categories, 'catalog.categories'], ['article_attributes', 'variant', $variants, 'catalog.variants'],
            ['article_requests', 'variant', $variants, 'catalog.variants']] as [$table, $column, $listed, $key]) {
            [$table, $column] = self::stored($table, $column);
            $stored = DB::table($table)->whereNotNull($column)->groupBy($column)->selectRaw("{$column} as value, count(*) as count")->get();
            foreach ($stored as $row) {
                if (! in_array($row->value, $listed, true)) {
                    $this->errors[] = sprintf('%d row(s) of %s.%s hold "%s", which %s does not list.', $row->count, $table, $column, $row->value, $key);
                }
            }
        }

        if (data_get($config, 'client.extra_field') === null) {
            [, $column] = self::stored('clients', 'extra_field');
            $count = DB::table('clients')->whereNotNull($column)->where($column, '!=', '')->count();
            if ($count > 0) {
                $this->warnings[] = "client.extra_field is off, but {$count} client(s) have a value in it: it would no longer show.";
            }
        }

        if (data_get($config, 'free_lines.enabled') === false) {
            $count = DB::table('order_lines')->where('is_free', true)->count();
            if ($count > 0) {
                $this->warnings[] = "free_lines is off, but {$count} order line(s) are free.";
            }
        }
    }

    /**
     * Where a value is stored, under today's names or, in a database whose names the installation has not renamed
     * yet, under the older names of its profile (aliases.schema; `php artisan passorde:rename-legacy`): deploy.sh
     * checks the profile with the new image before anything changes (RUNBOOK "Deploying").
     *
     * @return array{string, string}
     */
    private static function stored(string $table, string $column): array
    {
        $schema = (array) config('passorde.aliases.schema', []);
        $stored = Schema::hasTable($table) ? $table : (string) ($schema['tables'][$table] ?? $table);

        return [$stored, Schema::hasColumn($stored, $column) ? $column : (string) ($schema['columns'][$table][$column] ?? $column)];
    }

    private function text(string $key, mixed $value): void
    {
        if (! is_string($value) || trim($value) === '') {
            $this->errors[] = "{$key} is required (a text).";
        }
    }

    private function storedValue(string $key, mixed $value, int $max): void
    {
        if (! is_string($value) || trim($value) === '') {
            $this->errors[] = "{$key} is required (a text).";
        } elseif (mb_strlen($value) > $max) {
            $this->errors[] = "{$key} \"{$value}\" is longer than the {$max} characters it is stored in.";
        }
    }
}
