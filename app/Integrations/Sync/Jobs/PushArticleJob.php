<?php

namespace App\Integrations\Sync\Jobs;

use App\Domain\Catalog\NewArticles;
use App\Integrations\Sync\SyncProvider;
use App\Models\ArticleRequest;
use App\Support\SyncMode;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Creates in Evoliz an article asked for from an order, while the order form
 * waits (SyncProvider::pushArticle); its requests are logged in
 * passorde_requests with the request. One attempt at the queue level:
 * "Réessayer" sends it again.
 */
class PushArticleJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    public function __construct(public int $requestId) {}

    public function uniqueId(): string
    {
        return 'article-request-'.$this->requestId;
    }

    public function handle(SyncProvider $provider): void
    {
        $request = ArticleRequest::query()->find($this->requestId);

        if ($request === null) {
            return;
        }

        // Evoliz stopped since: the seller gets the article at once, as standalone.
        if (SyncMode::isFile()) {
            if ($request->status !== ArticleRequest::CREATED) {
                NewArticles::createLocally($request);
            }

            return;
        }

        $provider->pushArticle($request);
    }

    /**
     * The worker gave up (timeout, crash): the waiting form must hear it.
     */
    public function failed(?Throwable $exception): void
    {
        ArticleRequest::query()->whereKey($this->requestId)->where('status', ArticleRequest::PENDING)->update([
            'status' => ArticleRequest::FAILED,
            'error' => __('The push job stopped: :message', ['message' => $exception?->getMessage() ?? '?']),
        ]);
    }
}
