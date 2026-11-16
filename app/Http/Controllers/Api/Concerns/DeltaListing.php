<?php

namespace App\Http\Controllers\Api\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Delta listings for the phone's offline mirror (docs/API.md, "Offline
 * mirror"): rows whose updated_at is after `updated_since` and not after
 * `until`, 200 per page (1000 for the delivery history), keyset-paginated by id. The first page fixes `until`
 * (now) and the client sends it back with each `cursor`, so the set does not
 * move while it is being paged. Deactivated and deleted rows are included so
 * the mirror can hide them.
 */
trait DeltaListing
{
    public const DELTA_PER_PAGE = 200;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  Closure(Collection<int, TModel>): iterable<int, array<string, mixed>>  $present
     * @param  array<string, mixed>  $extra  Top-level keys added to the response
     */
    protected function deltaResponse(Request $request, Builder $query, Closure $present, array $extra = [], int $perPage = self::DELTA_PER_PAGE): JsonResponse
    {
        $validated = $request->validate([
            'updated_since' => ['nullable', 'date'],
            'until' => ['nullable', 'date'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ]);

        $until = isset($validated['until']) ? Carbon::parse($validated['until'])->utc() : now()->utc()->startOfSecond();
        $table = $query->getModel()->getTable();

        $page = $query
            ->when($validated['updated_since'] ?? null, fn (Builder $q, string $since) => $q->where("{$table}.updated_at", '>', Carbon::parse($since)->utc()))
            ->where("{$table}.updated_at", '<=', $until)
            ->orderBy("{$table}.id")
            ->cursorPaginate($perPage);

        /** @var Collection<int, TModel> $items */
        $items = collect($page->items());

        return response()->json([
            'data' => collect($present($items))->values(),
            'meta' => [
                'per_page' => $perPage,
                'next_cursor' => $page->nextCursor()?->encode(),
                'until' => $until->toIso8601String(),
            ],
            ...$extra,
        ]);
    }
}
