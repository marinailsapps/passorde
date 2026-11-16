<?php

namespace App\Integrations\Sync\Push;

use Closure;
use Passorde\Api\RequestContext;

/**
 * passorde_requests.subject_type of the requests a push makes
 * (Passorde\Api\RequestContext::subject), with the app's id as subject_id:
 *
 *     SELECT kind, status, http_status, last_error FROM passorde_requests
 *     WHERE subject_type = 'order' AND subject_id = 1234 ORDER BY id;
 */
final class Subject
{
    public const ORDER = 'order';

    public const CLIENT = 'client';

    public const ARTICLE_REQUEST = 'article_request';

    public const STOCK_ADJUSTMENT = 'stock_adjustment';

    /**
     * RequestContext::subject(), whose own @throws names only its argument
     * check: whatever $work throws comes through.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     *
     * @throws \Throwable
     */
    public static function run(string $type, int $id, Closure $work): mixed
    {
        return RequestContext::subject($type, $id, $work);
    }
}
