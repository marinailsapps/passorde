<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\ArticleAttribute;
use App\Models\Order;
use App\Models\User;
use App\Support\SyncMode;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $isAdmin = $user instanceof User && $user->isAdmin();

        return Inertia::render('Home', [
            // Admin badges: orders waiting for approval, pushes that failed.
            'badges' => $isAdmin ? [
                'pending' => Order::query()->where('status', OrderStatus::Pending)->count(),
                'failed' => Order::query()->where('status', OrderStatus::Failed)->count(),
                // Articles whose category the report could not settle ("Attributs articles").
                'to_review' => ArticleAttribute::query()->where('needs_review', true)->whereHas('article', fn ($q) => $q->where('is_active', true))->count(),
                // File mode: approved orders the operator has not exported yet.
                ...(SyncMode::isFile() ? ['to_export' => Order::query()->toExport()->count()] : []),
            ] : null,
        ]);
    }
}
