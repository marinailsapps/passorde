<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\ArticleSearch;
use App\Domain\Catalog\CatalogExport;
use App\Enums\ActivityAction;
use App\Enums\SyncKind;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Article;
use App\Models\User;
use App\Support\SyncMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The catalogue with prices and stock, for every role (read-only); only admins
 * see the sync bar and may start a sync. In file mode
 * admins edit articles here, since no sync brings them.
 */
class CatalogController extends Controller
{
    public function index(Request $request): Response
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $search = $request->string('q')->toString();
        $inactive = $request->boolean('inactive');

        $articles = ArticleSearch::apply(Article::query()->withReserved(), $search)
            ->when(! $inactive, fn ($query) => $query->where('is_active', true))
            ->orderBy('reference')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Article $article) => [
                'id' => $article->id,
                'reference' => $article->reference,
                'designation' => $article->designation,
                'unit_price_ht' => $article->unit_price_ht,
                'vat_rate' => $article->vat_rate,
                'is_stocked' => $article->is_stocked,
                'stock_qty' => $article->stock_qty,
                'reserved_qty' => number_format((float) ($article->getAttribute('reserved_qty') ?? 0), 2, '.', ''),
                'is_active' => $article->is_active,
                'synced_at' => $article->synced_at?->toIso8601String(),
            ]);

        $user = $request->user();

        return Inertia::render('Catalog/Index', [
            'articles' => $articles,
            'filters' => ['q' => $search, 'inactive' => $inactive],
            'sync' => $user instanceof User && $user->isAdmin() && ! SyncMode::isFile() ? SyncPresenter::status(SyncKind::Articles) : null,
        ]);
    }

    /**
     * The clients' price list: active articles with prices, category and variant (CatalogExport).
     */
    public function export(CatalogExport $export): StreamedResponse
    {
        $filename = 'tarifs-'.now('Europe/Paris')->toDateString().'.xlsx';

        return response()->streamDownload(fn () => $export->write('php://output'), $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * File mode (sync.file). The new stock is an absolute value, set under the
     * article's lock so that it cannot interleave with a LocalStock move.
     */
    public function update(Request $request, Article $article): RedirectResponse
    {
        $validated = $request->validate([
            'designation' => ['required', 'string', 'max:2000'],
            'unit_price_ht' => ['required', 'numeric', 'min:0', 'max:99999', 'decimal:0,2'],
            'stock_qty' => ['nullable', 'numeric', 'min:-9999999', 'max:9999999', 'decimal:0,2'],
            'is_stocked' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($article, $validated, $request) {
            $locked = Article::query()->lockForUpdate()->findOrFail($article->id);
            $locked->fill($validated);
            $fields = array_keys($locked->getDirty());

            if ($fields !== []) {
                $locked->save();
                $user = $request->user();
                ActivityLog::record(ActivityAction::ArticleEdited, $user instanceof User ? $user : null, null, [
                    'article_id' => $locked->id,
                    'reference' => $locked->reference,
                    'fields' => $fields,
                ]);
            }
        });

        return back()->with('success', __('Article saved.'));
    }

    public function sync(Request $request): RedirectResponse
    {
        SyncPresenter::dispatch(SyncKind::Articles, $request);

        return back()->with('success', __('Synchronisation started.'));
    }
}
