<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EvolizDelivery;
use App\Models\EvolizUser;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Vendeurs Evoliz": maps each Evoliz user seen on delivery notes to an app
 * user, who then shows as the seller of BLs not created from an app order
 * (those show their order's author). Unmapped users show as "Evoliz".
 */
class EvolizUserController extends Controller
{
    public function index(): Response
    {
        $counts = EvolizDelivery::query()->toBase()->selectRaw('evoliz_user_id, count(*) as deliveries, max(documentdate) as last_date')
            ->groupBy('evoliz_user_id')->get()->keyBy('evoliz_user_id');

        return Inertia::render('Admin/EvolizUsers/Index', [
            'evolizUsers' => EvolizUser::query()->orderBy('label')->orderBy('evoliz_user_id')->get()->map(fn (EvolizUser $row) => [
                'id' => $row->id,
                'evoliz_user_id' => $row->evoliz_user_id,
                'label' => $row->label,
                'user_id' => $row->user_id,
                'deliveries' => (int) ($counts[$row->evoliz_user_id]->deliveries ?? 0),
                'last_date' => $counts[$row->evoliz_user_id]->last_date ?? null,
            ]),
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'is_active']),
        ]);
    }

    public function update(Request $request, EvolizUser $evolizUser): RedirectResponse
    {
        $validated = $request->validate(['user_id' => ['nullable', 'integer', Rule::exists('users', 'id')]]);
        $evolizUser->update(['user_id' => $validated['user_id'] ?? null]);

        // The phones mirror the seller with each BL: let them fetch these again.
        EvolizDelivery::withTrashed()->where('evoliz_user_id', $evolizUser->evoliz_user_id)->toBase()->update(['updated_at' => now()]);

        return back()->with('success', __('Seller saved.'));
    }
}
