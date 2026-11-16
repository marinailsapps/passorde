<?php

// A fictional profile with the mistakes the check must name.
return [
    'brnad' => ['name' => 'Typo'],
    'brand' => ['name' => '', 'colour' => '#ffffff', 'accent' => 'green'],
    'admin' => ['password' => 'x'],
    'catalog' => [
        'categories' => [
            ['value' => 'POMMIER', 'label' => 'Pommier', 'group' => 'fruitiers', 'colour' => 'red'],
            ['value' => 'POMMIER', 'label' => 'Pommier bis', 'group' => 'fruitiers'],
            ['value' => 'UNE CATEGORIE AU NOM BEAUCOUP TROP LONG', 'label' => 'Long', 'group' => 'fruitiers'],
            ['value' => 'POIRIER', 'group' => 'vergers'],
        ],
        'category_groups' => [['key' => 'fruitiers', 'label' => 'FRUITIERS']],
        'variants' => [],
        'classifier' => ['words' => ['CERISIER' => 'CERISIER'], 'synonyms' => []],
    ],
];
