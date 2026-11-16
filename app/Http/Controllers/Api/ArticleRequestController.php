<?php

namespace App\Http\Controllers\Api;

use App\Domain\Catalog\NewArticles;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpsertArticleRequest;
use App\Integrations\Sync\Jobs\PushArticleJob;
use App\Models\Article;
use App\Models\ArticleRequest;
use App\Models\User;
use App\Support\SyncMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A new article from the order form: queued for Evoliz here, and the form polls
 * until Evoliz has it; in file mode it exists when the first answer leaves. Never
 * calls Evoliz from the request. Contract in docs/API.md.
 */
class ArticleRequestController extends Controller
{
    public function upsert(UpsertArticleRequest $request, string $uuid): JsonResponse
    {
        $user = $this->user($request);
        $uuid = strtolower($uuid);
        abort_unless(preg_match('/^[0-9a-f-]{36}$/', $uuid) === 1, 404);

        $existing = ArticleRequest::query()->where('uuid', $uuid)->first();
        abort_if($existing !== null && $existing->user_id !== $user->id, 403);

        /** @var array{name: string, category: string, variant: string, unit_price_ht: mixed} $fields */
        $fields = $request->validated();
        $articleRequest = NewArticles::upsert($user, $uuid, $fields);

        if ($articleRequest->status !== ArticleRequest::CREATED) {
            if (SyncMode::isFile()) {
                NewArticles::createLocally($articleRequest);
            } else {
                PushArticleJob::dispatch($articleRequest->id);
            }
        }

        return response()->json(['request' => self::present($articleRequest->refresh())], $existing === null ? 201 : 200);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $articleRequest = ArticleRequest::query()->where('uuid', strtolower($uuid))->firstOrFail();
        abort_if($articleRequest->user_id !== $this->user($request)->id, 403);

        return response()->json(['request' => self::present($articleRequest)]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(ArticleRequest $request): array
    {
        $article = $request->article_id === null ? null : Article::query()->withReserved()->find($request->article_id);

        return [
            'uuid' => $request->uuid,
            'status' => $request->status,
            'error' => $request->error,
            'article' => $article === null ? null : CatalogController::article($article),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
