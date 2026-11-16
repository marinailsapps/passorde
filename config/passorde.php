<?php

use App\Support\Profile\Profile;

/*
| The product settings of this installation: what the app is called, its
| colours, the texts it sends, the product categories and the labels of the
| optional fields. The values below are the neutral defaults of a fresh
| installation. The installing developer sets their own in a private profile,
| outside the repository: a directory holding profile.php, which returns the
| sections it changes (same keys as below). PASSORDE_PROFILE names the
| directory (default: the app's profile/ directory, gitignored; "none": no
| profile). Check a profile with `php artisan passorde:check-profile`
| (docs/RUNBOOK.md "Installation profile").
|
| Server settings (switches, keys, ops limits) stay in .env and config/server.php.
*/

return Profile::apply([

    // The app's name, logo and colours (App\Support\Profile\Brand). name: the installed app's full name (web
    // manifest, Excel files' creator, the daily mail's text); short_name: the header, page titles, the home-screen
    // label, the login logo's text, the SMS (:brand) and the daily mail's subject. logo: a PNG file in the profile
    // directory (e.g. 'logo.png'), the source of the login logo and every icon; null: the neutral logo.
    // The manifest, icons and colours are fixed when the assets are built (docs/RUNBOOK.md "Installation profile").
    'brand' => [
        'name' => 'Passorde',
        'short_name' => 'Passorde',
        'logo' => null,
        'theme_color' => '#334155',
        'accent' => '#334155',
        'accent_strong' => '#1e293b',
        'accent_soft' => '#e2e8f0',
    ],

    // Where the phones keep their data (fixed when the assets are built): the IndexedDB databases "<prefix>" and
    // "<prefix>-user-<id>", the Web Lock "<prefix>-outbox" and the localStorage key "<prefix>.installBanner.lastShown".
    // Never change it once phones use the app: their unsent orders would stay in the old databases.
    'phone' => [
        'storage_prefix' => 'passorde',
    ],

    // The SMS a client receives (:brand, :number, :quantity, :units, :ttc; :bl with Evoliz).
    'messages' => [
        'sms' => ':brand : votre commande n° :number (:quantity :units, :ttc € TTC) est enregistrée. Merci !',
        'sms_with_bl' => ':brand : votre commande n° :number (:quantity :units, :ttc € TTC) est enregistrée, bon de livraison :bl. Merci !',
    ],

    // What one quantity of a product is called.
    'units' => [
        'singular' => 'unité',
        'plural' => 'unités',
    ],

    // Free lines in an order: their label and the text added to the designation sent to Evoliz.
    'free_lines' => [
        'enabled' => true,
        'label' => 'offert',
        'evoliz_suffix' => ' / offert',
    ],

    // One optional free field on clients (App\Domain\Clients\ClientExtraField), e.g.
    // ['label' => 'N° adhérent', 'csv' => 'numero_adherent', 'searchable' => true]: its label everywhere, its CSV column
    // (before "actif"), and whether the client search reads it. Null: no such field. Stored in clients.extra_field.
    'client' => [
        'extra_field' => null,
    ],

    // Product categories and variants (App\Domain\Catalog\Catalog; stored values: keep them once data uses them).
    'catalog' => [
        // [['value' => 'STORED VALUE', 'label' => 'Shown', 'group' => 'group_key'], …], in display order.
        'categories' => [],
        // What a category is called in the texts (:category, :categories) and the export's column header.
        'category_name' => ['singular' => 'catégorie', 'plural' => 'catégories'],
        // Tabs of the stock report, in order: [['key' => 'group_key', 'label' => 'TAB'], …].
        'category_groups' => [],
        // label: short (reports, exports); name: in forms and pickers (default: the label).
        // counts: an article of it without a category is "à vérifier", a new article and the bulk category may have it,
        // the first one is the default, and only its quantities count in the sales report.
        // group: every article of this variant goes to this stock tab and has no category.
        // Neither counts nor group: left out of the stock report (e.g. shipping costs).
        // The first counting variant is what an article gets when nothing says otherwise.
        'variants' => [
            ['value' => 'standard', 'label' => 'Standard', 'name' => 'Standard', 'counts' => true, 'group' => null],
        ],
        // The articles CSV columns of the category and variant (also always accepted: categorie, variante).
        'csv' => ['category' => 'categorie', 'variant' => 'variante'],
        // The example row of the articles CSV template. Null: built from the first category and variant.
        // Keys: reference, designation, vat, category, variant.
        'import_example' => null,
        // Guessing an article's category and variant from its reference and designation (App\Domain\Catalog\ArticleClassifier).
        // Null: no guessing, every article gets the default variant. Keys:
        //   words (long words: also truncated or with one typo), aliases (exact), phrases (text => category),
        //   weak_aliases (only when nothing else names a category), secondary (categories dropped beside another),
        //   variant_words (variant => [words]; a variant that does not count only without a category),
        //   forced_variant_words (variant => [words], before anything else; an entry may be a list of words that must all appear),
        //   classification_codes (Evoliz sale classification code => category or variant),
        //   weak_classification_codes (the same, only when the words name no category).
        'classifier' => null,
        // The "new article" button of the order form. Null: no button.
        // Keys: label (what the button and form call it), vat, reference and designation (placeholders {name},
        // {variant}, {category}, {category:3}), variant_codes (variant => ['reference' => …, 'designation' => …]),
        // complete_pattern (a regular expression a designation matches once the operator has completed it in Evoliz;
        // before that, the BL push asks Evoliz for it again; null: always complete).
        'new_article' => null,
        // VAT rates the CSV import accepts without a warning. Empty: no warning.
        'usual_vat' => [],
    ],

    // What each delivery note sent to Evoliz carries, and the payment types offered at first.
    'evoliz' => [
        // The Evoliz payment term of every BL (an id of GET /payterms on the account, e.g. "30 jours"). Evoliz requires
        // one: while it is null, approved orders fail before anything is sent. EVOLIZ_PAYTERM_ID overrides it (mocks).
        'payterm_id' => null,
        // The "indemnité forfaitaire pour frais de recouvrement" mention: Evoliz does not apply the company's setting to API BLs.
        'recovery_indemnity' => true,
        // The BL comment (:number, :author), followed by the order's comment. Null: "Commande :number saisie par :author".
        'delivery_comment' => null,
        // Evoliz payment type ids (1-13, the Evoliz connector notes in Passorde Connect) enabled when the payment types are first seeded; admins change them later.
        'enabled_paytypes' => [2, 3, 4, 5, 8],
    ],

    // The reports' titles (each one drives the page title, the menu card, the workbook's title, sheet and print header,
    // and the :stock_title / :write_offs_title placeholders of the texts), fixed tab names and write-off labels.
    'reports' => [
        'stock_title' => 'Stock par catégorie',
        'write_offs_title' => 'Casse et cadeaux',
        'stock_tabs' => [
            // all: the one list of articles when the profile has no categories.
            'all' => 'ARTICLES',
            'unclassified' => 'À CLASSER',
            'broken' => 'CASSE',
            'gifts' => 'CADEAUX',
            'material_gifts' => 'CADEAUX MATÉRIEL',
        ],
        'write_off_labels' => [
            'broken' => 'Casse',
            'order_gift' => 'Cadeau dans une commande',
            'gift' => 'Cadeau hors commande',
        ],
    ],

    // Whole French sentences by their English key in lang/fr.json, where these placeholders do not give the
    // wording this installation wants (App\Support\Profile\Wording). The texts of lang/fr.json may use :unit,
    // :units (units), :free (free_lines.label), :category, :categories (catalog.category_name) and :item
    // (catalog.new_article.label); :Units capitalises, :UNITS is upper case.
    'texts' => [],

    // Older names an installation still has to answer to after renaming fields or tables, because phones installed
    // before still send them, read them or keep them in their local data (App\Http\Api\ApiAliases,
    // resources/js/offline/legacy.ts, `php artisan passorde:rename-legacy`; docs/API.md "Old names"). Every map is
    // empty in a new installation and stays so: there is nothing older to answer to.
    //   client, article, totals, summary: API field names, current => old (a client object; an article of the mirror,
    //     a stock report row, a new-article request; an order's totals; an order summary). Requests may send the old
    //     name, answers carry it beside the current one; client, article and totals also rename the rows phones stored
    //     (fixed when the assets are built).
    //   stock_tabs: stock report tab key => old key, answered as `legacy_key`.
    //   routes: ['api' => [old path => current path], 'web' => [...]]; a web GET is redirected, anything else answered.
    //   texts: old key of this profile's `texts` => current key. groupings: old sales report grouping => current.
    //   schema: what the tables and columns were called before (current => old): ['tables' => [...],
    //     'columns' => [table => [column => old column]]], read by passorde:check-profile and passorde:rename-legacy.
    'aliases' => [
        'client' => [],
        'article' => [],
        'totals' => [],
        'summary' => [],
        'stock_tabs' => [],
        'routes' => ['api' => [], 'web' => []],
        'texts' => [],
        'groupings' => [],
        'schema' => ['tables' => [], 'columns' => []],
    ],

], env('PASSORDE_PROFILE'), env('APP_ENV'));
