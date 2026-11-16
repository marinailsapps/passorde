<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\LocalStock;
use App\Enums\ActivityAction;
use App\Enums\EvolizStockStatus;
use App\Enums\StockAdjustmentKind;
use App\Http\Controllers\Controller;
use App\Integrations\Sync\Jobs\AdjustStockJob;
use App\Models\ActivityLog;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Support\SyncMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The stock adjustments page: broken entries and gifts outside an order. Each entry
 * also adjusts the Evoliz stock (AdjustStockJob, through Passorde, so
 * test mode sends nothing); deleting one (soft) adds the quantity back. The list
 * shows the Evoliz status, with a retry for failed entries; a deleted entry stays
 * listed until Evoliz has its quantity back.
 *
 * In file mode the local stock takes the move in the same transaction, through
 * LocalStock: evoliz_applied then means "the local stock includes the deduction",
 * evoliz_before/after hold the local figures and evoliz_status stays null. The
 * stock report needs no change, since it only counts entries that are not applied.
 */
class StockAdjustmentController extends Controller
{
    /** An entry can be deleted this long after it was recorded. */
    public const DELETE_WINDOW_HOURS = 24;

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'kind' => ['nullable', Rule::enum(StockAdjustmentKind::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'article_id' => ['nullable', 'integer'],
        ]);
        $user = $this->user($request);

        $entries = StockAdjustment::withTrashed()
            ->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('evoliz_applied', true))
            ->with(['article', 'client', 'user'])
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('happened_on', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('happened_on', '<=', $to))
            ->when($filters['article_id'] ?? null, fn ($q, $id) => $q->where('article_id', $id))
            ->orderByDesc('happened_on')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (StockAdjustment $entry) => [
                'id' => $entry->id,
                'kind' => $entry->kind->value,
                'happened_on' => $entry->happened_on->toDateString(),
                'quantity' => $entry->quantity,
                'article' => ['id' => $entry->article->id, 'reference' => $entry->article->reference, 'designation' => $entry->article->designation],
                'client' => $entry->client ? ['id' => $entry->client->id, 'company_name' => $entry->client->company_name] : null,
                'note' => $entry->note,
                'user' => $entry->user->name,
                'created_at' => $entry->created_at->toIso8601String(),
                'deleted' => $entry->trashed(),
                'evoliz_status' => $entry->evoliz_status?->value,
                'evoliz_error' => $entry->evoliz_error,
                'evoliz_before' => $entry->evoliz_before,
                'evoliz_after' => $entry->evoliz_after,
                'can_delete' => ! $entry->trashed() && self::canDelete($entry, $user),
                'can_retry' => ! SyncMode::isFile() && $entry->evolizOutOfStep() && $entry->evoliz_status !== EvolizStockStatus::Pending,
            ]);

        return Inertia::render('Admin/StockAdjustments/Index', [
            'entries' => $entries,
            'filters' => [
                'kind' => $filters['kind'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'article_id' => isset($filters['article_id']) ? (int) $filters['article_id'] : null,
            ],
            'today' => now('Europe/Paris')->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::enum(StockAdjustmentKind::class)],
            'article_id' => ['required', 'integer', Rule::exists('articles', 'id')],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'happened_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Europe/Paris')->addDay()->toDateString()],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id'), Rule::prohibitedIf($request->input('kind') === StockAdjustmentKind::Broken->value)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $user = $this->user($request);

        if (SyncMode::isFile()) {
            $entry = DB::transaction(function () use ($validated, $user) {
                $entry = StockAdjustment::query()->create([...$validated, 'user_id' => $user->id]);
                $move = LocalStock::apply($entry->article_id, -(float) $entry->quantity);

                if ($move !== null) {
                    $entry->update(['evoliz_before' => $move['before'], 'evoliz_after' => $move['after'], 'evoliz_applied' => true]);
                }

                ActivityLog::record(ActivityAction::StockAdjustmentAdded, $user, null, self::meta($entry));

                return $entry;
            });
        } else {
            $entry = StockAdjustment::query()->create([...$validated, 'user_id' => $user->id, 'evoliz_status' => EvolizStockStatus::Pending]);
            ActivityLog::record(ActivityAction::StockAdjustmentAdded, $user, null, self::meta($entry));
            AdjustStockJob::dispatch($entry->id)->afterCommit();
        }

        return back()->with('success', $entry->kind === StockAdjustmentKind::Broken ? __('Broken entry recorded.') : __('Gift recorded.'));
    }

    public function destroy(Request $request, StockAdjustment $adjustment): RedirectResponse
    {
        $user = $this->user($request);
        if (! self::canDelete($adjustment, $user)) {
            throw new AccessDeniedHttpException(__('Entries can be deleted only within :hours hours, by their author or an admin.', ['hours' => self::DELETE_WINDOW_HOURS]));
        }

        if (SyncMode::isFile()) {
            DB::transaction(fn () => $this->destroyLocally($adjustment, $user));

            return back()->with('success', __('Entry deleted.'));
        }

        ActivityLog::record(ActivityAction::StockAdjustmentDeleted, $user, null, self::meta($adjustment));
        $adjustment->delete();
        if ($adjustment->evoliz_applied) {
            $adjustment->update(['evoliz_status' => EvolizStockStatus::Pending, 'evoliz_error' => null]);
        }
        // Always: a deduction still running when the entry was deleted is added back once it ends.
        AdjustStockJob::dispatch($adjustment->id)->afterCommit();

        return back()->with('success', __('Entry deleted.'));
    }

    /**
     * The entry's lock makes a double click give the quantity back once.
     */
    private function destroyLocally(StockAdjustment $adjustment, User $user): void
    {
        $entry = StockAdjustment::query()->lockForUpdate()->find($adjustment->id);

        if ($entry === null) {
            return;
        }

        ActivityLog::record(ActivityAction::StockAdjustmentDeleted, $user, null, self::meta($entry));

        if ($entry->evoliz_applied) {
            $move = LocalStock::apply($entry->article_id, (float) $entry->quantity);
            $entry->update(['evoliz_before' => $move['before'] ?? null, 'evoliz_after' => $move['after'] ?? null, 'evoliz_applied' => false]);
        }

        $entry->delete();
    }

    public function retry(Request $request, StockAdjustment $adjustment): RedirectResponse
    {
        $user = $this->user($request);
        if (! $adjustment->evolizOutOfStep()) {
            return back()->with('success', __('The Evoliz stock is already up to date for this entry.'));
        }

        $adjustment->update(['evoliz_status' => EvolizStockStatus::Pending, 'evoliz_error' => null]);
        ActivityLog::record(ActivityAction::StockAdjustmentRetried, $user, null, self::meta($adjustment));
        AdjustStockJob::dispatch($adjustment->id);

        return back()->with('success', __('Sent to Evoliz again.'));
    }

    private static function canDelete(StockAdjustment $entry, User $user): bool
    {
        return $entry->created_at->gt(Carbon::now()->subHours(self::DELETE_WINDOW_HOURS))
            && ($entry->user_id === $user->id || $user->isAdmin());
    }

    /**
     * @return array<string, mixed>
     */
    private static function meta(StockAdjustment $entry): array
    {
        return [
            'adjustment_id' => $entry->id,
            'kind' => $entry->kind->value,
            'article_id' => $entry->article_id,
            'reference' => $entry->article()->value('reference'),
            'quantity' => $entry->quantity,
            'happened_on' => $entry->happened_on->toDateString(),
            'client_id' => $entry->client_id,
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
