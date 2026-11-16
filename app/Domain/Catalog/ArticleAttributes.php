<?php

namespace App\Domain\Catalog;

use App\Enums\AttributeSource;
use App\Models\Article;
use App\Models\ArticleAttribute;

/**
 * Keeps article_attributes filled: the classifier's guess for articles
 * nobody has set by hand. Values from a workbook (reports:seed-attributes) or an admin are
 * never overwritten here.
 */
class ArticleAttributes
{
    /**
     * Creates the attributes of an article, or refreshes an automatic guess
     * (the reference or designation may have changed in Evoliz).
     */
    public function classify(Article $article): ArticleAttribute
    {
        $existing = ArticleAttribute::query()->where('article_id', $article->id)->first();

        if ($existing !== null && $existing->source !== AttributeSource::Auto) {
            return $existing;
        }

        $guess = ArticleClassifier::classify($article->reference, $article->designation_raw ?? $article->designation, $article->sale_classification_code);

        $attribute = $existing ?? new ArticleAttribute(['article_id' => $article->id]);
        $attribute->fill([
            'category' => $guess['category'],
            'variant' => $guess['variant'],
            'needs_review' => $guess['needs_review'],
            'source' => AttributeSource::Auto,
        ]);
        $attribute->save();

        return $attribute;
    }
}
