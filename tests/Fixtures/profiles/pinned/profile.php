<?php

/*
| The profile the test suite runs on when PASSORDE_PROFILE names none
| (tests/TestCase.php): the values the suite's assertions are written against
| (Evoliz request bodies, import warnings, report labels). An installation never
| uses it. A real profile under test (PASSORDE_PROFILE set to its directory)
| keeps its own values instead.
|
| The catalogue below is a fictional fruit-tree nursery: categories, groups,
| variants, classifier lexicon (aliases, typos, phrases) and new-article templates,
| so the tests exercise every rule of the classifier.
*/

return [
    'units' => ['singular' => 'plant', 'plural' => 'plants'],
    'free_lines' => [
        'enabled' => true,
        'label' => 'plant offert',
        'evoliz_suffix' => ' / plant offert',
    ],
    'client' => [
        'extra_field' => ['label' => 'N° adhérent', 'csv' => 'numero_adherent', 'searchable' => true],
    ],
    'catalog' => [
        'categories' => [
            ['value' => 'POMMIER', 'label' => 'POMMIER', 'group' => 'pommier'],
            ['value' => 'POIRIER', 'label' => 'POIRIER', 'group' => 'po_pr_ce'],
            ['value' => 'PRUNIER', 'label' => 'PRUNIER', 'group' => 'po_pr_ce'],
            ['value' => 'CERISIER', 'label' => 'CERISIER', 'group' => 'po_pr_ce'],
            ['value' => 'ABRICOTIER', 'label' => 'ABRICOTIER', 'group' => 'abricotier'],
            ['value' => 'FIGUIER', 'label' => 'FIGUIER', 'group' => 'figuier_kaki'],
            ['value' => 'KAKI', 'label' => 'KAKI', 'group' => 'figuier_kaki'],
            ['value' => 'NOYER', 'label' => 'NOYER', 'group' => 'fruits_secs'],
            ['value' => 'NOISETIER', 'label' => 'NOISETIER', 'group' => 'fruits_secs'],
            ['value' => 'GRENADIER', 'label' => 'GRENADIER', 'group' => 'fruits_secs'],
            ['value' => 'COGNASSIER', 'label' => 'COGNASSIER', 'group' => 'po_pr_ce'],
            ['value' => 'AMANDIER', 'label' => 'AMANDIER', 'group' => 'fruits_secs'],
            ['value' => 'AVELINIER', 'label' => 'AVELINIER', 'group' => 'fruits_secs'],
            ['value' => 'GRIOTTIER', 'label' => 'GRIOTTIER', 'group' => 'po_pr_ce'],
            ['value' => 'PECHER', 'label' => 'PECHER', 'group' => 'fruits_secs'],
            ['value' => 'MURIER', 'label' => 'MURIER', 'group' => 'fruits_secs'],
            ['value' => 'PETITS FRUITS', 'label' => 'PETITS FRUITS', 'group' => 'autres'],
            ['value' => 'SUREAU', 'label' => 'SUREAU', 'group' => 'autres'],
            ['value' => 'TAMARIS', 'label' => 'TAMARIS', 'group' => 'autres'],
            ['value' => 'VIGNE', 'label' => 'VIGNE', 'group' => 'autres'],
            ['value' => 'ROSIER DE MAI', 'label' => 'ROSIER DE MAI', 'group' => 'autres'],
            ['value' => 'BAIE ROSE', 'label' => 'BAIE ROSE', 'group' => 'autres'],
            ['value' => 'CEDRAT', 'label' => 'CEDRAT', 'group' => 'fruits_secs'],
            ['value' => 'HOUX', 'label' => 'HOUX', 'group' => 'autres'],
            ['value' => 'FRAMBOISIER', 'label' => 'FRAMBOISIER', 'group' => 'po_pr_ce'],
            ['value' => 'AUTRE', 'label' => 'AUTRE', 'group' => 'autres'],
        ],
        'category_name' => ['singular' => 'essence', 'plural' => 'essences'],
        'category_groups' => [
            ['key' => 'pommier', 'label' => 'POMMIER'],
            ['key' => 'fruits_secs', 'label' => 'FRUITS SECS'],
            ['key' => 'figuier_kaki', 'label' => 'FIGUIER KAKI'],
            ['key' => 'abricotier', 'label' => 'ABRICOTIER'],
            ['key' => 'po_pr_ce', 'label' => 'POIRIER PRUNIER CERISIER'],
            ['key' => 'autres', 'label' => 'AUTRES ESSENCES'],
            ['key' => 'materiel', 'label' => 'MATERIEL'],
        ],
        'variants' => [
            ['value' => 'nu', 'label' => 'NU', 'name' => 'Racines nues', 'counts' => true, 'group' => null],
            ['value' => 'motte', 'label' => 'MOTTE', 'name' => 'En motte', 'counts' => true, 'group' => null],
            ['value' => 'material', 'label' => 'MATÉRIEL', 'name' => 'Matériel', 'counts' => false, 'group' => 'materiel'],
            ['value' => 'other', 'label' => 'AUTRE', 'name' => 'Autre (hors stock)', 'counts' => false, 'group' => null],
        ],
        'csv' => ['category' => 'essence', 'variant' => 'type'],
        'import_example' => [
            'reference' => 'EXEMPLE MT POM', 'designation' => 'EXEMPLE MOTTE POMMIER PG M9 LOT 0000', 'vat' => 5.5,
            'category' => 'POMMIER', 'variant' => 'motte',
        ],
        'classifier' => [
            'words' => [
                'POMMIER' => 'POMMIER', 'POIRIER' => 'POIRIER', 'POIRIERS' => 'POIRIER', 'PRUNIER' => 'PRUNIER',
                'PRUNIERS' => 'PRUNIER', 'CERISIER' => 'CERISIER', 'CERISIERS' => 'CERISIER', 'ABRICOTIER' => 'ABRICOTIER',
                'FIGUIER' => 'FIGUIER', 'FIGUIERS' => 'FIGUIER', 'KAKIS' => 'KAKI', 'NOYER' => 'NOYER', 'NOYERS' => 'NOYER',
                'NOISETIER' => 'NOISETIER', 'NOISETIERS' => 'NOISETIER', 'GRENADIER' => 'GRENADIER', 'AMANDIER' => 'AMANDIER', 'AVELINIER' => 'AVELINIER',
                'GRIOTTIER' => 'GRIOTTIER', 'COGNASSIER' => 'COGNASSIER', 'PECHER' => 'PECHER', 'PECHERS' => 'PECHER', 'MURIER' => 'MURIER',
                'SUREAU' => 'SUREAU', 'TAMARIS' => 'TAMARIS', 'TAMARIN' => 'TAMARIS', 'VIGNE' => 'VIGNE',
                'VIGNES' => 'VIGNE', 'ROSIER' => 'ROSIER DE MAI', 'CEDRAT' => 'CEDRAT', 'HOUX' => 'HOUX', 'FRAMBOISIER' => 'FRAMBOISIER',
            ],
            'aliases' => ['POM' => 'POMMIER', 'CER' => 'CERISIER', 'KAKI' => 'KAKI', 'NOI' => 'NOISETIER', 'COG' => 'COGNASSIER', 'PFR' => 'PETITS FRUITS'],
            'weak_aliases' => [
                'PO' => 'POIRIER', 'NOIX' => 'NOYER', 'GRIOTTE' => 'GRIOTTIER', 'GRIOTTES' => 'GRIOTTIER', 'ABR' => 'ABRICOTIER',
            ],
            'phrases' => [
                'BAIE ROSE' => 'BAIE ROSE', 'ARBRE A COINGS' => 'COGNASSIER', 'PLAQUEMINIER DOUX' => 'KAKI',
                'GRIOTTES DU NORD' => 'GRIOTTIER', 'PETITS FRUITS' => 'PETITS FRUITS',
            ],
            'secondary' => ['BAIE ROSE'],
            'variant_words' => [
                'motte' => ['MOTTE', 'MOT', 'MOTS', 'MOTTES', 'MT', 'C3', 'C5'],
                'material' => [
                    'SECATEUR', 'SECATEURS', 'GANT', 'GANTS', 'ARROSOIR', 'ARROSOIRS', 'TERREAU', 'TUTEUR', 'TUTEURS', 'GEL',
                    'POT', 'POTS', 'BECHE', 'BECHES', 'RATEAU', 'RATEAUX', 'BROUETTE', 'BROUETTES', 'FILET', 'FILETS', 'TABLIER',
                ],
            ],
            'forced_variant_words' => ['other' => [['PORT', 'FRAIS']]],
            'classification_codes' => ['02' => 'other', 'TR' => 'material', 'POMMIER' => 'POMMIER'],
            'weak_classification_codes' => ['20' => 'material'],
        ],
        'new_article' => [
            'label' => 'Nouvelle variété',
            'vat' => 5.5,
            'reference' => '{name} {variant} {category:3}',
            'designation' => '{name} {variant} {category}',
            'variant_codes' => ['motte' => ['reference' => 'MT', 'designation' => 'MOTTE']],
            'complete_pattern' => '/\bPG\b|\bLOT\b/iu',
        ],
        'usual_vat' => [5.5, 20.0],
    ],
    // The report titles and labels the existing tests assert.
    'reports' => [
        'stock_title' => 'Stock par essence',
        'write_offs_title' => 'Plants abîmés et cadeaux',
        'stock_tabs' => ['unclassified' => 'À CLASSER', 'broken' => 'PLANTS ABIMES', 'gifts' => 'CADEAUX CLIENT', 'material_gifts' => 'CADEAUX MATERIEL'],
        'write_off_labels' => ['broken' => 'Plants abîmés', 'order_gift' => 'Cadeau dans une commande', 'gift' => 'Cadeau hors commande'],
    ],
    'evoliz' => [
        // The test account's payment term (tests/Fixtures/evoliz).
        'payterm_id' => 5,
    ],
    // Sentences the existing tests assert (lang/fr.json has the neutral wording).
    'texts' => [
        'A free line needs a paid line of the same article.' => 'Un plant offert doit accompagner une ligne payante du même article.',
        // The reports' wording the existing tests assert.
        'Broken / Gifts' => 'Plants abîmés / Cadeaux',
        'Broken entries' => 'Plants abîmés',
        'Broken entry recorded.' => 'Plants abîmés enregistrées.',
        'activity.stock_adjustment_added' => 'Plants abîmés / cadeau enregistré',
        'activity.stock_adjustment_deleted' => 'Plants abîmés / cadeau supprimé',
        'Evoliz stock, reserved, broken and gifts per article, stock to come and what to order. Excel download.' => 'Stock Evoliz, réservé, plants abîmés et cadeaux par variété, stock à terme et quantités à commander. Export Excel.',
        'Broken entries and gifts only; the stock is today’s.' => 'Plants abîmés et cadeaux seulement ; le stock est celui du jour.',
        'Broken stock and gifts made outside an order. Each entry is also subtracted from the Evoliz stock of the article; deleting it (within 24 hours) puts the quantity back.' => 'Plants abîmés en pépinière et cadeaux faits hors commande. Chaque saisie est aussi déduite du stock Evoliz de l’article ; la supprimer (dans les 24 heures) remet la quantité en stock.',
        'Broken entries / gifts not applied to the Evoliz stock (:count)' => 'Plants abîmés / cadeaux non reportés dans le stock Evoliz (:count)',
        'Every broken entry and gift of a period, in an order or outside, with its author and the Evoliz stock status. Excel download.' => 'Toutes les plants abîmés et tous les cadeaux d’une période, dans une commande ou hors commande, avec leur auteur et l’état du stock Evoliz. Export Excel.',
        'What was delivered over a period, from the Evoliz delivery notes: quantities, HT, TTC per category, article, client, month or département. Excel download. Also works offline.' => 'Ce qui a été livré sur une période, d’après les bons de livraison Evoliz : plants, HT, TTC par essence, variété, client, mois ou département. Export Excel. Fonctionne aussi hors ligne.',
        'By article' => 'Par variété',
        'By variant' => 'Nu / motte',
        'Source: Evoliz delivery notes; orders still waiting are not counted. Quantities leave out the variants that are not counted.' => 'Source : bons de livraison Evoliz ; les commandes en attente ne sont pas comptées. Les plants excluent le matériel.',
        'Broken stock and gifts made outside an order. Each entry is also subtracted from the stock of the article; deleting it (within 24 hours) puts the quantity back.' => 'Plants abîmés en pépinière et cadeaux faits hors commande. Chaque saisie est aussi déduite du stock de l’article ; la supprimer (dans les 24 heures) remet la quantité en stock.',
        'The exported orders of a period: quantities, HT, TTC per category, article, client, month or département. Excel download.' => 'Les commandes exportées d’une période : plants, HT, TTC par essence, variété, client, mois ou département. Export Excel.',
        'Stock, reserved, broken and gifts per article, stock to come and what to order. Excel download.' => 'Stock, réservé, plants abîmés et cadeaux par variété, stock à terme et à commander. Export Excel.',
        'Every broken entry and gift of a period, in an order or outside, with its author. Excel download.' => 'Toutes les plants abîmés et tous les cadeaux d’une période, dans une commande ou hors commande, avec leur auteur. Export Excel.',
        'Source: exported orders; the others are not counted. Quantities leave out the variants that are not counted.' => 'Source : commandes exportées ; les autres ne sont pas comptées. Les plants excluent le matériel.',
        'sales.~material' => 'Matériel',
        'stock.column.variant' => 'NU/MOTTE',
        'reports.file.write_offs' => 'plants-abimes-cadeaux',
        'sales.sheet.variant' => 'Nu-Motte',
        'sales.card.quantities' => 'Plants (hors matériel)',
    ],
];
