<?php

namespace App\Http\Controllers\Api;

use App\Domain\Catalog\ArticleSearch;
use App\Domain\Catalog\Catalog;
use App\Domain\Clients\ClientSearch;
use App\Domain\Clients\DuplicateFinder;
use App\Domain\Orders\OrderPresenter;
use App\Http\Api\ApiAliases;
use App\Http\Controllers\Api\Concerns\DeltaListing;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Client;
use App\Models\Paytype;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Look-ups used by the order form: clients, articles, paytypes (recent orders: SalesController).
 */
class CatalogController extends Controller
{
    use DeltaListing;

    private const LIMIT = 20;

    /**
     * Offline mirror of the client directory: every client changed since
     * `updated_since`, inactive ones included (docs/API.md).
     */
    public function clientDelta(Request $request): JsonResponse
    {
        return $this->deltaResponse($request, Client::query(), fn (Collection $clients) => $clients->map(fn (Client $client) => [
            ...OrderPresenter::client($client),
            'is_active' => $client->is_active,
            'updated_at' => $client->updated_at?->toIso8601String(),
        ]));
    }

    /**
     * Offline mirror of the catalogue, with stock and reservations at sync
     * time. `reservations` lists every article's current reserved quantity,
     * since reservations change with orders, not with the article row.
     */
    public function articleDelta(Request $request): JsonResponse
    {
        return $this->deltaResponse(
            $request,
            Article::query()->withReserved()->with('attribute'),
            fn (Collection $articles) => $articles->map(fn (Article $article) => ApiAliases::add([
                ...self::article($article),
                // For the sales report offline.
                'category' => $article->attribute?->category,
                'variant' => $article->attribute?->variant,
                'is_active' => $article->is_active,
                'updated_at' => $article->updated_at?->toIso8601String(),
            ], ApiAliases::article())),
            ['reservations' => (object) DB::table('article_reservations')->orderBy('article_id')->pluck('reserved_qty', 'article_id')
                ->map(fn ($qty) => number_format((float) $qty, 2, '.', ''))->all()],
        );
    }

    public function clients(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $q = $request->string('q')->trim()->toString();

        if (mb_strlen($q) < ClientSearch::MIN_LENGTH) {
            return response()->json(['data' => []]);
        }

        // Best match first (ClientSearch), the same order the phone uses offline.
        $ids = ClientSearch::rankedIds(Client::query()->where('is_active', true), $q, self::LIMIT);
        $clients = ClientSearch::inOrder(Client::query(), $ids)->get();

        return response()->json(['data' => $clients->map(fn (Client $client) => OrderPresenter::client($client))]);
    }

    public function possibleDuplicate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:120'],
            'postcode' => ['required', 'string', 'max:10'],
        ]);

        $client = DuplicateFinder::find($validated['company_name'], $validated['postcode']);

        return response()->json(['possible_duplicate' => $client ? OrderPresenter::client($client) : null]);
    }

    public function articles(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $q = $request->string('q')->trim()->toString();

        if (mb_strlen($q) < ArticleSearch::MIN_LENGTH) {
            return response()->json(['data' => []]);
        }

        $ids = ArticleSearch::rankedIds(Article::query()->where('is_active', true), $q, self::LIMIT);
        $articles = ClientSearch::inOrder(Article::query()->withReserved(), $ids)->get();

        return response()->json(['data' => $articles->map(fn (Article $article) => self::article($article))]);
    }

    /**
     * The categories of the "new article" form, in the profile's order (catalog.categories).
     */
    public function categories(): JsonResponse
    {
        return response()->json(['data' => array_map(fn (array $category) => ['value' => $category['value'], 'label' => $category['label']], Catalog::categories())]);
    }

    public function paytypes(): JsonResponse
    {
        return response()->json([
            'data' => Paytype::query()->where('is_enabled', true)->orderBy('label')->get(['id', 'label']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function article(Article $article): array
    {
        return [
            'id' => $article->id,
            'reference' => $article->reference,
            'designation' => $article->designation,
            'unit_price_ht' => $article->unit_price_ht,
            'vat_rate' => $article->vat_rate,
            'is_stocked' => $article->is_stocked,
            'stock_qty' => $article->stock_qty,
            'reserved_qty' => number_format((float) ($article->getAttribute('reserved_qty') ?? 0), 2, '.', ''),
        ];
    }
}
