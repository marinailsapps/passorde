<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Orders\ApprovalService;
use App\Domain\Orders\OrderExport;
use App\Domain\Orders\OrderFilters;
use App\Domain\Orders\OrderPresenter;
use App\Domain\Orders\StockConflictFinder;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Support\SyncMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Admin side of orders: every user's orders, approval, rejection, retry.
 * Approving queues the Evoliz push; nothing here calls Evoliz itself.
 */
class OrderAdminController extends Controller
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly StockConflictFinder $stockConflicts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $admin = $this->admin($request);
        $filters = $request->validate([
            // "conflict": orders that would take an article's stock below zero.
            // "to_export", "exported": file mode only.
            'status' => ['nullable', Rule::in([...array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases()), 'all', 'conflict', ...(SyncMode::isFile() ? ['to_export', 'exported'] : [])])],
            ...OrderFilters::rules(),
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $status = $filters['status'] ?? OrderStatus::Pending->value;

        $base = fn () => OrderFilters::query($filters);

        // Per-status counts for the filter chips, with every other filter applied.
        $counts = $base()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $conflicts = $this->stockConflicts->all();
        $conflictIds = array_keys($conflicts);

        $page = $base()
            ->with(['client', 'user'])
            ->when($status === 'conflict', fn ($query) => $query->whereIn('id', $conflictIds))
            ->when($status === 'to_export', fn ($query) => $query->toExport())
            ->when($status === 'exported', fn ($query) => $query->exported())
            ->when(! in_array($status, ['all', 'conflict', 'to_export', 'exported'], true), fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (Order $order) => [
                ...OrderPresenter::summary($order, $admin),
                'stock_conflicts' => $conflicts[$order->id] ?? [],
            ]),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'counts' => [
                ...collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $s) => [$s->value => (int) ($counts[$s->value] ?? 0)]),
                'all' => (int) $counts->sum(),
                'conflict' => $conflictIds === [] ? 0 : $base()->whereIn('id', $conflictIds)->count(),
                ...(SyncMode::isFile() ? ['to_export' => $base()->toExport()->count(), 'exported' => $base()->exported()->count()] : []),
            ],
        ]);
    }

    public function approve(Request $request, Order $order): JsonResponse
    {
        $admin = $this->admin($request);
        // Unticked "Appliquer": the order goes, its client correction does not.
        $validated = $request->validate(['apply_client_change' => ['sometimes', 'boolean']]);
        $order = $this->approvals->approve($admin, $order, (bool) ($validated['apply_client_change'] ?? true));

        return response()->json(['order' => OrderPresenter::detail($order, $admin)]);
    }

    public function reject(Request $request, Order $order): JsonResponse
    {
        $admin = $this->admin($request);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $order = $this->approvals->reject($admin, $order, trim($validated['reason']));

        return response()->json(['order' => OrderPresenter::detail($order, $admin)]);
    }

    public function retry(Request $request, Order $order): JsonResponse
    {
        $admin = $this->admin($request);
        // Standalone, an order Evoliz may hold: settled only on the admin's word that Evoliz has no BL for its number.
        $validated = $request->validate(['checked_evoliz' => ['sometimes', 'boolean']]);
        $order = $this->approvals->retry($admin, $order, (bool) ($validated['checked_evoliz'] ?? false));

        return response()->json(['order' => OrderPresenter::detail($order, $admin)]);
    }

    /**
     * "Envoyer à Evoliz": an order approved while Evoliz was unavailable, not exported.
     */
    public function sendToEvoliz(Request $request, Order $order): JsonResponse
    {
        $admin = $this->admin($request);
        // An order that was in a downloaded export file goes only with this explicit confirmation.
        $validated = $request->validate(['confirm_download' => ['sometimes', 'boolean']]);
        $order = $this->approvals->sendToEvoliz($admin, $order, (bool) ($validated['confirm_download'] ?? false));

        return response()->json(['order' => OrderPresenter::detail($order, $admin)]);
    }

    /**
     * "Renvoyer": queues the client's SMS again (SMS_ENABLED, sent orders only).
     */
    public function resendSms(Request $request, Order $order): JsonResponse
    {
        $admin = $this->admin($request);
        $this->approvals->resendSms($admin, $order);

        return response()->json(['order' => OrderPresenter::detail($order->refresh(), $admin)]);
    }

    /**
     * Approves several pending orders; each gets its own push job. Orders that
     * are no longer pending are skipped, not failed.
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        $admin = $this->admin($request);
        $validated = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid', 'distinct'],
        ]);

        $approved = [];
        $skipped = [];
        foreach (Order::query()->whereIn('uuid', $validated['uuids'])->orderBy('id')->get() as $order) {
            try {
                $approved[] = $this->approvals->approve($admin, $order)->number;
            } catch (ConflictHttpException $e) {
                $skipped[] = ['number' => $order->number, 'reason' => $e->getMessage()];
            }
        }

        return response()->json(['approved' => $approved, 'skipped' => $skipped]);
    }

    /**
     * The uuids of the file the operator just downloaded (file mode), or the one order
     * an admin marks again with Evoliz back (approved during a lapse), never
     * "everything approved": an order approved since then was not in the file.
     * Orders already exported are skipped, so a replay changes nothing.
     */
    public function markExported(Request $request): JsonResponse
    {
        $admin = $this->admin($request);
        $validated = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:'.OrderExport::MAX_ORDERS],
            'uuids.*' => ['required', 'uuid', 'distinct'],
        ]);

        $exported = [];
        $skipped = [];
        foreach (Order::query()->whereIn('uuid', $validated['uuids'])->orderBy('id')->get() as $order) {
            try {
                $exported[] = $this->approvals->markExported($admin, $order)->number;
            } catch (ConflictHttpException $e) {
                $skipped[] = ['number' => $order->number, 'reason' => $e->getMessage()];
            }
        }

        return response()->json(['exported' => $exported, 'skipped' => $skipped]);
    }

    /**
     * Undoes the export of one order, for a mis-click (file mode, or an order approved during a lapse).
     */
    public function unmarkExported(Request $request, Order $order): JsonResponse
    {
        $admin = $this->admin($request);
        $order = $this->approvals->unmarkExported($admin, $order);

        return response()->json(['order' => OrderPresenter::detail($order, $admin)]);
    }

    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);

        return $user;
    }
}
