<?php

use App\Domain\Catalog\ArticleClassifier;
use Tests\TestCase;

// The lexicon is the profile's (catalog.classifier): the pinned test profile, or the real one under test.
uses(TestCase::class);

/*
| References and designations of the fictional nursery (variety, form, pot size,
| rootstock): abbreviations, truncations, typos, HTML entities.
*/

it('classifies catalogue articles', function (string $reference, string $designation, ?string $classification, ?string $category, string $type, bool $review) {
    expect(ArticleClassifier::classify($reference, $designation, $classification))->toBe([
        'category' => $category,
        'variant' => $type,
        'needs_review' => $review,
    ]);
})->with([
    'plain word' => ['ALBA POMMIER', 'ALBA POMMIER / PG M9', '55', 'POMMIER', 'nu', false],
    'C3 + POM, variety called Griotte' => ['LUNA C3 POM', 'LUNA GRIOTTE MOTTE POMMIER / PG M26', 'POMMIER', 'POMMIER', 'motte', false],
    'Evoliz POMMIER beats a weak alias' => ['LUNA TIGE', 'LUNA GRIOTTE / PG M26 / CAL : 8-10', 'POMMIER', 'POMMIER', 'nu', false],
    'apple with a form word' => ['RUBI TIGE', 'RUBI TIGE POMMIER / PG : M106', '55', 'POMMIER', 'nu', false],
    'truncated POMM' => ['DORA MOTTE POMM', 'DORA MOTTE POMM', '55', 'POMMIER', 'motte', false],
    'truncated POMMIE' => ['ELKA MOTTE POMMIE', 'ELKA MOTTE POMMIE', '55', 'POMMIER', 'motte', false],
    'underscore' => ['FARO_BIS', 'FARO BIS POMMIER / PG : MAXMA14', '55', 'POMMIER', 'nu', false],
    'MT' => ['GALIO MT POM', 'GALIO MOTTE POMMIER / PG : M9', '55', 'POMMIER', 'motte', false],
    'C5' => ['HELMA C5 POM', 'HELMA C5 POMMIER', '55', 'POMMIER', 'motte', false],
    'misspelt POMNIER' => ['IRIDA', 'IRIDA POMNIER / CAL : 6-8', '55', 'POMMIER', 'nu', false],
    'misspelt POMIER' => ['JOLAN', 'JOLAN POMIER', '55', 'POMMIER', 'nu', false],
    'PO' => ['KORA PO POIRIER', 'KORA PO POIRIER', '55', 'POIRIER', 'nu', false],
    'PO alone, potted' => ['LUMEN PO C3', 'LUMEN PO MOTTE', '55', 'POIRIER', 'motte', false],
    'NOIX' => ['NOVA NOIX', 'NOVA NOIX / PG : G41', '55', 'NOYER', 'nu', false],
    'NOIX after an underscore' => ['ZP006_NOIX', 'ZP006 NOIX / PG : G41', '55', 'NOYER', 'nu', false],
    'NOIX beside the full word' => ['VERA NOIX', 'VERA NOYER / PG : G41', '55', 'NOYER', 'nu', false],
    'NOIX potted' => ['ORLA NOIX MOTTE', 'ORLA NOIX MOTTE / PG : G41', '55', 'NOYER', 'motte', false],
    'NOI' => ['PIRA TIGE NOI', 'PIRA TIGE NOISETIER / PG : COLT', '55', 'NOISETIER', 'nu', false],
    'misspelt ABRISOTIER' => ['QUINA C3 ABRISOTIER', 'QUINA MOTTE ABRICOTIER', '55', 'ABRICOTIER', 'motte', false],
    'ABR' => ['RIVO_ABR', 'RIVO ABR / PG : MYROBOLAN', '55', 'ABRICOTIER', 'nu', false],
    'SUREAU truncated' => ['SALVO SUREA', 'SALVO SUREAU / PG :G41', '55', 'SUREAU', 'nu', false],
    'CERISIER with underscore' => ['TALIA_CERISIER', 'TALIA CERISIER', '55', 'CERISIER', 'nu', false],
    'CER' => ['ULMO CER', 'ULMO CER', '55', 'CERISIER', 'nu', false],
    'HTML entity' => ['VESNA FIGUIER MOTT&Eacute', 'VESNA FIGUIER MOTT&Eacute;', '55', 'FIGUIER', 'motte', false],
    'category only in the designation' => ['WILMA', 'WILMA KAKI / PG : G41 2', '55', 'KAKI', 'nu', false],
    'KAKI potted' => ['XENA C3 KAKI', 'XENA C3 KAKI', '55', 'KAKI', 'motte', false],
    'COG' => ['YARO COG', 'YARO COG / PG : BA29', '55', 'COGNASSIER', 'nu', false],
    'phrase' => ['YVETTE', 'YVETTE ARBRE A COINGS', '55', 'COGNASSIER', 'nu', false],
    'AMANDIER' => ['ZELIA OPALINE AMANDIER', 'ZELIA OPALINE AMANDIER', '55', 'AMANDIER', 'nu', false],
    'CEDRAT' => ['AMBRA CEDRAT', 'AMBRA CEDRAT', '55', 'CEDRAT', 'nu', false],
    'TAMARIS' => ['BRENNA TAMARIS', 'BRENNA TAMARIS', '55', 'TAMARIS', 'nu', false],
    'GRIOTTE' => ['CORVO TIGE GRIOTTE', 'CORVO TIGE GRIOTTE / PG : COLT', '55', 'GRIOTTIER', 'nu', false],
    'PRUNIER' => ['DALIA PRUNIER', 'DALIA PRUNIER', '55', 'PRUNIER', 'nu', false],
    'PRUNIER potted' => ['EBANO PRUNIER MOTTE', 'EBANO PRUNIER MOTTE', '55', 'PRUNIER', 'motte', false],
    'BAIE ROSE, MOTTE' => ['FELIX MOTTE BAIE RO', 'FELIX MOTTE BAIE ROSE', '55', 'BAIE ROSE', 'motte', false],
    'BAIE ROSE beside another category is part of its name' => ['FIGO BAIE ROSE', 'FIGO BAIE ROSE FIGUIER', '55', 'FIGUIER', 'nu', false],
    'misspelt FRAMBOISIEK' => ['GINO FRAMBOISIEK', 'GINO FRAMBOISIEK', '55', 'FRAMBOISIER', 'nu', false],
    'PETITS FRUITS' => ['HUGO PFR', 'HUGO PETITS FRUITS', '55', 'PETITS FRUITS', 'nu', false],
    'compost (classification)' => ['TERREAU', 'TERREAU EN VRAC 3 livraisons par an', 'TR', null, 'material', false],
    'watering can' => ['ARROSOIR', 'ARROSOIR 10 L', '55', null, 'material', false],
    'gel' => ['GEL CICATRISANT 5 L', 'GEL CICATRISANT 5 LITRES', '20', null, 'material', false],
    'goods at 20 % VAT' => ['POMPE_12V', 'POMPE 12V 220V', '20', null, 'material', false],
    'shipping' => ['PORT', 'Frais de Port Facturés', '02', null, 'other', false],
    'no category anywhere' => ['ILARIA', 'ILARIA / PG : M106', '55', null, 'nu', true],
    'two categories' => ['CROSS', 'CROSS POMMIER FIGUIER', '55', null, 'nu', true],
]);
