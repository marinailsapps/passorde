<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Paytype;
use App\Support\SyncMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Which payment methods sales people can pick. In Evoliz mode the same toggle
 * sits on the synchronisation page; file mode has no such page.
 */
class PaytypeController extends Controller
{
    public function index(): Response
    {
        abort_unless(SyncMode::isFile(), 404);

        return Inertia::render('Admin/Paytypes/Index', [
            'paytypes' => Paytype::query()->orderBy('label')->get(['id', 'label', 'is_enabled']),
        ]);
    }

    public function update(Request $request, Paytype $paytype): RedirectResponse
    {
        $validated = $request->validate(['is_enabled' => ['required', 'boolean']]);
        $paytype->update($validated);

        return back()->with('success', __('Payment method saved.'));
    }
}
