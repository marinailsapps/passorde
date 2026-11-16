<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SyncKind;
use App\Enums\SyncStatus;
use App\Http\Controllers\Controller;
use App\Models\Paytype;
use App\Models\SyncRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SyncController extends Controller
{
    public function index(): Response
    {
        $runs = SyncRun::query()->with('user')->latest('id')->limit(50)->get();

        return Inertia::render('Admin/Sync/Index', [
            'runs' => $runs->map(fn (SyncRun $run) => SyncPresenter::present($run)),
            'active' => $runs->contains(fn (SyncRun $run) => in_array($run->status, [SyncStatus::Queued, SyncStatus::Running], true)
                && $run->created_at->gt(now()->subHour())),
            'kinds' => array_map(fn (SyncKind $kind) => $kind->value, SyncKind::cases()),
            'paytypes' => Paytype::query()->orderBy('label')->get(['id', 'evoliz_paytype_id', 'label', 'is_enabled']),
        ]);
    }

    public function run(Request $request, string $kind): RedirectResponse
    {
        $request->merge(['kind' => $kind])->validate([
            'kind' => ['required', Rule::enum(SyncKind::class)],
        ]);

        SyncPresenter::dispatch(SyncKind::from($kind), $request);

        return back()->with('success', __('Synchronisation started.'));
    }
}
