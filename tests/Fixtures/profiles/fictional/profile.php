<?php

// A complete, fictional installation profile (a tree nursery), valid for the profile check.
return [
    'brand' => ['name' => 'Pépinière Exemple', 'short_name' => 'Exemple', 'logo' => 'logo.png', 'theme_color' => '#2f5d3a', 'accent' => '#2f5d3a', 'accent_strong' => '#1f3f27', 'accent_soft' => '#e6f0e8'],
    // Storage names as an installation older than this setting had them ("<prefix>-user-<id>", "<prefix>-outbox"…).
    'phone' => ['storage_prefix' => 'pepiniere'],
    'messages' => [
        'sms' => 'Exemple : commande :number (:quantity plants, :ttc € TTC) enregistrée.',
        'sms_with_bl' => 'Exemple : commande :number (:quantity plants, :ttc € TTC) enregistrée, BL :bl.',
    ],
    'units' => ['singular' => 'plant', 'plural' => 'plants'],
    'free_lines' => ['enabled' => true, 'label' => 'plant offert', 'evoliz_suffix' => ' / plant offert'],
    'client' => ['extra_field' => ['label' => 'N° adhérent', 'csv' => 'numero_adherent', 'searchable' => true]],
    'catalog' => [
        'categories' => [
            ['value' => 'POMMIER', 'label' => 'Pommier', 'group' => 'fruitiers'],
            ['value' => 'POIRIER', 'label' => 'Poirier', 'group' => 'fruitiers'],
            ['value' => 'ERABLE', 'label' => 'Érable', 'group' => 'ornement'],
        ],
        'category_groups' => [
            ['key' => 'fruitiers', 'label' => 'FRUITIERS'],
            ['key' => 'ornement', 'label' => 'ORNEMENT'],
            ['key' => 'outils', 'label' => 'OUTILS'],
        ],
        'variants' => [
            ['value' => 'racine', 'label' => 'Racines nues', 'counts' => true, 'group' => null],
            ['value' => 'godet', 'label' => 'Godet', 'counts' => true, 'group' => null],
            ['value' => 'outil', 'label' => 'Outil', 'counts' => false, 'group' => 'outils'],
        ],
        'classifier' => [
            'words' => ['POMMIER' => 'POMMIER', 'POIRIER' => 'POIRIER'],
            'aliases' => ['POM' => 'POMMIER'],
            'weak_aliases' => [],
            'phrases' => ['ERABLE DU JAPON' => 'ERABLE'],
            'variant_words' => ['godet' => ['GODET', 'POT'], 'outil' => ['SECATEUR']],
            'classification_codes' => ['OUT' => 'outil'],
        ],
        'new_article' => [
            'label' => 'Nouvelle variété', 'vat' => 10.0,
            'reference' => '{name} {variant} {category:3}', 'designation' => '{name} {variant} {category}',
            'variant_codes' => ['godet' => ['reference' => 'GD', 'designation' => 'GODET']],
        ],
        'usual_vat' => [10, 20],
    ],
    'evoliz' => [
        'payterm_id' => 7,
        'recovery_indemnity' => false,
        'delivery_comment' => 'Bon de commande :number (:author)',
        'enabled_paytypes' => [2, 4],
    ],
    'reports' => [
        'stock_title' => 'Stock par essence',
        'write_offs_title' => 'Pertes et cadeaux',
        'stock_tabs' => ['unclassified' => 'À CLASSER', 'broken' => 'PERTES', 'gifts' => 'CADEAUX', 'material_gifts' => 'CADEAUX OUTILS'],
        'write_off_labels' => ['broken' => 'Pertes', 'order_gift' => 'Cadeau dans une commande', 'gift' => 'Cadeau hors commande'],
    ],
];
