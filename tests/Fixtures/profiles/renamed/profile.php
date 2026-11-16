<?php

/*
| A fictional installation that renamed some fields and tables after its phones
| were installed: its older names (aliases) let those phones keep working, and
| `php artisan passorde:rename-legacy` moves its database to the current names.
| Read by tests/Feature/Api/OldFieldNamesTest.php, RenameLegacyTest.php and
| resources/js/offline/legacy.test.ts (its maps, copied there).
*/

return [
    'aliases' => [
        'client' => ['extra_field' => 'member_code'],
        'article' => ['category' => 'family', 'variant' => 'kind'],
        'totals' => ['quantity' => 'pieces'],
        'summary' => ['total_quantity' => 'total_pieces'],
        'stock_tabs' => ['broken' => 'casse_ancien', 'gifts' => 'cadeaux_ancien', 'material_gifts' => 'cadeaux_materiel_ancien'],
        'routes' => [
            'api' => ['api/families' => 'api/categories'],
            'web' => ['admin/family-thresholds' => 'admin/category-thresholds', 'admin/article-attributes/bulk-family' => 'admin/article-attributes/bulk-category'],
        ],
        'texts' => ['sales.sheet.kind' => 'sales.sheet.variant'],
        'groupings' => ['family' => 'category', 'kind' => 'variant'],
        'schema' => [
            'tables' => ['category_thresholds' => 'family_thresholds'],
            'columns' => [
                'clients' => ['extra_field' => 'member_code'],
                'article_attributes' => ['category' => 'family', 'variant' => 'kind'],
                'article_requests' => ['category' => 'family', 'variant' => 'kind'],
                'category_thresholds' => ['category' => 'family'],
                'orders' => ['total_quantity' => 'total_pieces'],
            ],
        ],
    ],
];
