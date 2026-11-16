<?php

namespace App\Http\Controllers;

use App\Domain\Orders\OrderExport;
use App\Domain\Orders\OrderPresenter;
use App\Models\Order;
use App\Models\Paytype;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia shells for the order screens. Data changes go through the JSON API.
 */
class OrderPageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Orders/Index');
    }

    public function adminIndex(): Response
    {
        return Inertia::render('Admin/Orders/Index', [
            'authors' => User::query()->whereHas('orders')->orderBy('name')->get(['id', 'name']),
            'exportLimit' => OrderExport::MAX_ORDERS,
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $this->user($request);
        $template = null;

        // "Dupliquer": prefill from one of the user's orders (or any order for an admin).
        if ($request->filled('from')) {
            $source = Order::query()->where('uuid', $request->string('from')->toString())->first();

            if ($source !== null && ($source->user_id === $user->id || $user->isAdmin())) {
                $template = OrderPresenter::detail($source, $user);
            }
        }

        return Inertia::render('Orders/Wizard', [
            'order' => null,
            'template' => $template,
            'paytypes' => $this->paytypes(),
        ]);
    }

    public function edit(Request $request, string $uuid): Response|RedirectResponse
    {
        $user = $this->user($request);
        $order = $this->find($uuid);

        if ($order === null) {
            return Inertia::render('Offline/Resolve');
        }

        if (! $order->isEditableBy($user)) {
            return redirect()->route('orders.show', $order->uuid)->with('error', __('This order can no longer be changed.'));
        }

        return Inertia::render('Orders/Wizard', [
            'order' => OrderPresenter::detail($order, $user),
            'template' => null,
            'paytypes' => $this->paytypes(),
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $user = $this->user($request);
        $order = $this->find($uuid);

        if ($order === null) {
            return Inertia::render('Offline/Resolve');
        }

        abort_unless($order->user_id === $user->id || $user->isAdmin(), 403);

        return Inertia::render('Orders/Show', ['order' => OrderPresenter::detail($order, $user)]);
    }

    /**
     * An order the server does not know may still be on the phone (confirmed
     * offline, not sent yet): the Offline/Resolve page looks for it there.
     * Deleted orders stay a 404.
     */
    private function find(string $uuid): ?Order
    {
        $order = Order::withTrashed()->where('uuid', strtolower($uuid))->first();
        abort_if($order?->trashed() ?? false, 404);

        return $order;
    }

    /**
     * @return Collection<int, Paytype>
     */
    private function paytypes(): Collection
    {
        return Paytype::query()->where('is_enabled', true)->orderBy('label')->get(['id', 'label']);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
