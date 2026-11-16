<?php

namespace App\Http\Controllers\Api;

use App\Domain\Clients\ClientSearch;
use App\Domain\Orders\OrderPresenter;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\StockConflictFinder;
use App\Enums\OrderStatus;
use App\Http\Controllers\Api\Concerns\DeltaListing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpsertOrderRequest;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Orders API, session-authenticated. Contract documented in docs/API.md.
 */
class OrderController extends Controller
{
    use DeltaListing;

    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if ($request->has('updated_since')) {
            return $this->delta($request, $user);
        }
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = Order::query()
            ->where('user_id', $user->id)
            ->with(['client', 'user'])
            // Authors see failed pushes as approved, so "approved" includes them.
            ->when($filters['status'] ?? null, fn ($query, $status) => in_array($status, ['approved', 'failed'], true)
                ? $query->whereIn('status', ['approved', 'failed'])
                : $query->where('status', $status))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', Carbon::parse($from, 'Europe/Paris')->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', Carbon::parse($to, 'Europe/Paris')->endOfDay()->utc()))
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->whereIn('client_id', ClientSearch::rankedIds(Client::query(), $q)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (Order $order) => OrderPresenter::summary($order, $user)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * Offline mirror of the caller's own orders: full order objects changed
     * since `updated_since`, deleted ones included with `deleted_at`.
     */
    private function delta(Request $request, User $user): JsonResponse
    {
        $query = Order::withTrashed()->where('user_id', $user->id)->with(['client', 'user', 'paytype', 'approver', 'lines.article']);

        return $this->deltaResponse($request, $query, function (Collection $orders) use ($user) {
            $stock = OrderPresenter::stockMap($orders->flatMap(fn (Order $order) => $order->lines->pluck('article_id'))->filter()->all());
            $conflicts = $user->isAdmin() ? app(StockConflictFinder::class)->all() : [];

            return $orders->map(fn (Order $order) => [
                ...OrderPresenter::detail($order, $user, $stock, $conflicts[$order->id] ?? []),
                'deleted_at' => $order->deleted_at?->toIso8601String(),
            ]);
        });
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($order->user_id === $user->id || $user->isAdmin(), 403, __('This order belongs to another user.'));

        return response()->json(['order' => OrderPresenter::detail($order, $user)]);
    }

    public function upsert(UpsertOrderRequest $request, string $uuid): JsonResponse
    {
        $user = $this->user($request);
        $result = $this->orders->save($user, strtolower($uuid), $request->validated());
        $duplicate = $result['possible_duplicate'];

        return response()->json([
            'order' => OrderPresenter::detail($result['order'], $user),
            'possible_duplicate' => $duplicate ? OrderPresenter::client($duplicate) : null,
        ], $result['created'] ? 201 : 200);
    }

    public function destroy(Request $request, Order $order): Response
    {
        $user = $this->user($request);

        // Replaying a delete (outbox) is fine once it has happened.
        if (! ($order->trashed() && ($order->user_id === $user->id || $user->isAdmin()))) {
            $this->orders->delete($user, $order);
        }

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
