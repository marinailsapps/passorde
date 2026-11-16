<?php

namespace App\Domain\Catalog;

use App\Enums\AttributeSource;
use App\Integrations\Sync\Sinks\Adoption;
use App\Integrations\Sync\Sinks\ArticleSink;
use App\Integrations\Sync\Sinks\EvolizText;
use App\Models\Article;
use App\Models\ArticleAttribute;
use App\Models\ArticleRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Articles added from an order, when the profile has a "new article" button
 * (catalog.new_article; null: no button, the API answers 404). The seller gives
 * the name, category, counting variant and price; reference, designation and VAT
 * follow the profile's templates. The operator may complete the designation in
 * Evoliz (the catalogue sync brings it here) or, in file mode, on the catalogue
 * page, where the article exists at once (createLocally).
 */
final class NewArticles
{
    public static function enabled(): bool
    {
        return is_array(config('passorde.catalog.new_article'));
    }

    /** What the button and form call the new article (catalog.new_article.label); null without a button. */
    public static function label(): ?string
    {
        return self::enabled() ? (string) config('passorde.catalog.new_article.label') : null;
    }

    public static function vatRate(): float
    {
        return (float) config('passorde.catalog.new_article.vat', 0);
    }

    /**
     * Creates the request, or corrects one Evoliz has not got yet (same uuid: "Réessayer").
     *
     * @param  array{name: string, category: string, variant: string, unit_price_ht: mixed}  $fields
     */
    public static function upsert(User $user, string $uuid, array $fields): ArticleRequest
    {
        $request = ArticleRequest::query()->where('uuid', $uuid)->first();

        if ($request?->status === ArticleRequest::CREATED) {
            return $request;
        }

        $name = Str::upper(Str::squish($fields['name']));
        $category = $fields['category'];
        $type = $fields['variant'];

        $request ??= new ArticleRequest(['uuid' => $uuid, 'user_id' => $user->id]);
        $request->fill([
            'name' => $name,
            'category' => $category,
            'variant' => $type,
            'unit_price_ht' => $fields['unit_price_ht'],
            'reference' => self::reference($name, $category, $type),
            'designation' => self::designation($name, $category, $type),
            'status' => ArticleRequest::PENDING,
            'error' => null,
        ])->save();

        return $request;
    }

    /** The reference, from catalog.new_article.reference, the way the catalogue's references read. */
    public static function reference(string $name, string $category, string $type): string
    {
        return self::fill('reference', $name, $category, $type);
    }

    /** The designation, from catalog.new_article.designation: the operator may complete it in Evoliz. */
    public static function designation(string $name, string $category, string $type): string
    {
        return self::fill('designation', $name, $category, $type);
    }

    /**
     * A template with {name}, {variant} (the variant's code for this template, from
     * variant_codes, else nothing), {category} and {category:N} (its first N letters);
     * empty placeholders leave no extra space.
     */
    private static function fill(string $template, string $name, string $category, string $variant): string
    {
        $text = (string) config("passorde.catalog.new_article.{$template}", '{name}');
        $code = (string) config("passorde.catalog.new_article.variant_codes.{$variant}.{$template}", '');

        $text = (string) preg_replace_callback('/\{category:(\d+)\}/', fn (array $m) => mb_substr($category, 0, (int) $m[1]), $text);
        $text = strtr($text, ['{name}' => $name, '{variant}' => $code, '{category}' => $category]);

        return Str::squish($text);
    }

    /**
     * What most articles of this category with the new article's VAT use (Evoliz
     * splits sales by classification): the catalogue says it, so nothing here goes
     * stale. Null: Evoliz decides.
     */
    public static function classificationFor(string $category): ?int
    {
        $mostUsed = fn (?string $only) => DB::table('articles')
            ->join('article_attributes', 'article_attributes.article_id', '=', 'articles.id')
            ->where('articles.is_active', true)
            ->where('articles.vat_rate', self::vatRate())
            ->whereNotNull('articles.sale_classification_id')
            ->whereIn('article_attributes.variant', Catalog::countingVariants())
            ->when($only, fn ($query) => $query->where('article_attributes.category', $only))
            ->groupBy('articles.sale_classification_id')
            ->orderByRaw('count(*) desc')
            ->value('articles.sale_classification_id');

        $id = $mostUsed($category) ?? $mostUsed(null);

        return $id === null ? null : (int) $id;
    }

    /**
     * Evoliz has the article: it joins the catalogue with the category and variant
     * the seller chose, marked by hand so that the sync's classifier keeps them.
     *
     * @param  array<string, mixed>  $row  The article as Evoliz returned it
     */
    public static function link(ArticleRequest $request, array $row): Article
    {
        return DB::transaction(function () use ($request, $row) {
            $evolizId = (int) $row['articleid'];
            $article = Article::query()->where('evoliz_article_id', $evolizId)->first()
                ?? Adoption::article($evolizId, EvolizText::plain($row['reference'] ?? null))?->forceFill(['evoliz_article_id' => $evolizId])
                ?? new Article(['evoliz_article_id' => $evolizId]);
            $article->fill([...ArticleSink::map($row), 'synced_at' => now()])->save();

            ArticleAttribute::query()->updateOrCreate(['article_id' => $article->id], [
                'category' => $request->category,
                'variant' => $request->variant,
                'needs_review' => false,
                'source' => AttributeSource::Manual,
            ]);

            $request->update(['status' => ArticleRequest::CREATED, 'error' => null, 'article_id' => $article->id]);

            return $article;
        });
    }

    /**
     * File mode: nobody to wait for. A reference already there is the same article,
     * whatever the case, and keeps its price and stock; it comes back if it was
     * inactive, since the seller is about to order it.
     */
    public static function createLocally(ArticleRequest $request): Article
    {
        return DB::transaction(function () use ($request) {
            $article = Article::query()->whereNull('evoliz_article_id')->whereRaw('lower(reference) = lower(?)', [$request->reference])->first()
                ?? Article::query()->createOrFirst(['reference' => $request->reference, 'evoliz_article_id' => null], [
                    'designation' => $request->designation,
                    'unit_price_ht' => $request->unit_price_ht,
                    'vat_rate' => self::vatRate(),
                    'is_stocked' => true,
                    'stock_qty' => 0,
                    'is_active' => true,
                ]);

            if (! $article->is_active) {
                $article->update(['is_active' => true]);
            }

            ArticleAttribute::query()->firstOrCreate(['article_id' => $article->id], [
                'category' => $request->category,
                'variant' => $request->variant,
                'needs_review' => false,
                'source' => AttributeSource::Manual,
            ]);

            $request->update(['status' => ArticleRequest::CREATED, 'error' => null, 'article_id' => $article->id]);

            return $article;
        });
    }

    /**
     * The designation still lacks what the operator adds in Evoliz: it does not match
     * catalog.new_article.complete_pattern (a regular expression). No pattern: never.
     */
    public static function lacksNumbers(string $designation): bool
    {
        $pattern = config('passorde.catalog.new_article.complete_pattern');

        return is_string($pattern) && $pattern !== '' && preg_match($pattern, $designation) !== 1;
    }
}
