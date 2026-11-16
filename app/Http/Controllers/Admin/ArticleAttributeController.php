<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\ArticleSearch;
use App\Domain\Catalog\Catalog;
use App\Domain\Clients\ClientSearch;
use App\Enums\AttributeSource;
use App\Http\Api\ApiAliases;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleAttribute;
use App\Models\CategoryThreshold;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Category, variant and reorder threshold of the articles (the stock report's
 * data that Evoliz lacks; App\Domain\Catalog\Catalog), and the thresholds per category. Whatever an admin sets
 * here is "manual": the catalogue sync and the seed never change it again.
 */
class ArticleAttributeController extends Controller
{
    public function index(Request $request): Response
    {
        ApiAliases::accept($request, ApiAliases::article()); // a page opened under older names (aliases)
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'review' => ['nullable', 'boolean'],
            'category' => ['nullable', Rule::in([...Catalog::categoryValues(), 'none'])],
            'type' => ['nullable', Rule::in(Catalog::variantValues())],
        ]);

        $query = Article::query()
            ->where('articles.is_active', true)
            ->join('article_attributes', 'article_attributes.article_id', '=', 'articles.id')
            ->select('articles.*')
            ->with('attribute')
            ->when($filters['review'] ?? false, fn ($q) => $q->where('article_attributes.needs_review', true))
            ->when(($filters['category'] ?? null) === 'none', fn ($q) => $q->whereNull('article_attributes.category'))
            ->when(($filters['category'] ?? 'none') !== 'none', fn ($q) => $q->where('article_attributes.category', $filters['category']))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('article_attributes.variant', $type));

        if (filled($filters['q'] ?? null)) {
            ClientSearch::inOrder($query, ArticleSearch::rankedIds(Article::query()->where('is_active', true), $filters['q']));
        } else {
            $query->orderBy('articles.reference');
        }

        $articles = $query->paginate(50)->withQueryString()->through(fn (Article $article) => [
            'id' => $article->attribute?->id,
            'article_id' => $article->id,
            'reference' => $article->reference,
            'designation' => $article->designation,
            'category' => $article->attribute?->category,
            'variant' => $article->attribute?->variant,
            'reorder_threshold' => $article->attribute?->reorder_threshold,
            'needs_review' => (bool) $article->attribute?->needs_review,
            'source' => $article->attribute?->source->value,
        ]);

        return Inertia::render('Admin/ArticleAttributes/Index', [
            'articles' => $articles,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'review' => (bool) ($filters['review'] ?? false),
                'category' => $filters['category'] ?? '',
                'type' => $filters['type'] ?? '',
            ],
            'categories' => self::categories(),
            'toReview' => ArticleAttribute::query()->where('needs_review', true)->whereHas('article', fn ($q) => $q->where('is_active', true))->count(),
        ]);
    }

    public function update(Request $request, ArticleAttribute $attribute): RedirectResponse
    {
        ApiAliases::accept($request, ApiAliases::article()); // a page opened under older names (aliases)
        $validated = $request->validate([
            'category' => ['nullable', Rule::in(Catalog::categoryValues())],
            'variant' => ['required', Rule::in(Catalog::variantValues())],
            'reorder_threshold' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $type = $validated['variant'];
        $category = Catalog::variantGroup($type) !== null ? null : ($validated['category'] ?? null);

        $attribute->fill([
            'category' => $category,
            'variant' => $type,
            'reorder_threshold' => $validated['reorder_threshold'] ?? null,
            'needs_review' => Catalog::needsReview($category, $type),
            'source' => AttributeSource::Manual,
            'updated_by' => $request->user()?->id,
        ])->save();

        return back()->with('success', __('Saved.'));
    }

    public function bulkCategory(Request $request): RedirectResponse
    {
        ApiAliases::accept($request, ApiAliases::article()); // a page opened under older names (aliases)
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct', 'exists:article_attributes,id'],
            'category' => ['required', Rule::in(Catalog::categoryValues())],
        ]);

        $changed = ArticleAttribute::query()
            ->whereIn('id', $validated['ids'])
            ->whereIn('variant', Catalog::countingVariants());
        // The phones' article mirror carries the category (sales report offline).
        Article::query()->whereIn('id', (clone $changed)->select('article_id'))->update(['updated_at' => now()]);

        $count = $changed
            ->update([
                'category' => $validated['category'],
                'needs_review' => false,
                'source' => AttributeSource::Manual->value,
                'updated_by' => $request->user()?->id,
                'updated_at' => now(),
            ]);

        return back()->with('success', trans_choice(':count articles updated.', $count));
    }

    public function thresholds(): Response
    {
        $set = CategoryThreshold::query()->get()->mapWithKeys(fn (CategoryThreshold $t) => [$t->category => $t->threshold]);

        return Inertia::render('Admin/ArticleAttributes/Thresholds', [
            'categories' => collect(Catalog::categories())->map(fn (array $category) => [
                'category' => $category['value'],
                'group' => Catalog::groupLabel($category['group']),
                'threshold' => $set[$category['value']] ?? null,
                'articles' => ArticleAttribute::query()->where('category', $category['value'])->whereHas('article', fn ($q) => $q->where('is_active', true))->count(),
            ])
                // A threshold stored for a category the profile no longer lists: shown, so that it can be cleared.
                ->concat($set->keys()->reject(fn (string $category) => Catalog::isCategory($category))->sort()->values()->map(fn (string $category) => [
                    'category' => $category,
                    'group' => __('Not in the catalogue'),
                    'threshold' => $set[$category],
                    'articles' => ArticleAttribute::query()->where('category', $category)->whereHas('article', fn ($q) => $q->where('is_active', true))->count(),
                    // Clear-only: a category the profile no longer lists gets no new threshold.
                    'orphan' => true,
                ]))
                ->all(),
        ]);
    }

    public function updateThresholds(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'thresholds' => ['required', 'array'],
            'thresholds.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        foreach ($validated['thresholds'] as $category => $threshold) {
            // A category the profile no longer lists: its stored threshold can only be cleared.
            if (! Catalog::isCategory((string) $category) && $threshold !== null) {
                continue;
            }
            if ($threshold === null) {
                CategoryThreshold::query()->where('category', $category)->delete();
            } else {
                CategoryThreshold::query()->updateOrCreate(['category' => $category], ['threshold' => $threshold, 'updated_by' => $request->user()?->id]);
            }
        }

        return back()->with('success', __('Thresholds saved.'));
    }

    /**
     * @return list<array{value: string, label: string, group: string}>
     */
    public static function categories(): array
    {
        return array_map(fn (array $category) => ['value' => $category['value'], 'label' => $category['label'], 'group' => Catalog::groupLabel($category['group'])], Catalog::categories());
    }
}
