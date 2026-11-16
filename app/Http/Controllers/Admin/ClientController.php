<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Clients\ClientChanges;
use App\Domain\Clients\ClientExtraField;
use App\Domain\Clients\ClientSearch;
use App\Enums\ClientChangeSource;
use App\Enums\ClientChangeStatus;
use App\Enums\ClientSource;
use App\Enums\SyncKind;
use App\Http\Api\ApiAliases;
use App\Http\Controllers\Controller;
use App\Integrations\Sync\Jobs\ApplyClientChangeJob;
use App\Integrations\Sync\Jobs\PushClientJob;
use App\Models\Client;
use App\Models\ClientChange;
use App\Rules\FrenchPhone;
use App\Support\SyncMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $search = $request->string('q')->toString();
        $unlinked = $request->boolean('unlinked');
        $inactive = $request->boolean('inactive');

        $clients = ClientSearch::apply(Client::query(), $search)
            ->when($unlinked, fn ($query) => $query->whereNull('evoliz_client_id'))
            ->when(! $inactive, fn ($query) => $query->where('is_active', true))
            ->orderBy('company_name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Client $client) => [
                'id' => $client->id,
                'company_name' => $client->company_name,
                'contact_name' => $client->contact_name,
                'postcode' => $client->postcode,
                'town' => $client->town,
                'phone' => $client->phone,
                'extra_field' => $client->extra_field,
                'is_active' => $client->is_active,
                'linked' => $client->isLinkedToEvoliz(),
            ]);

        return Inertia::render('Admin/Clients/Index', [
            'clients' => $clients,
            'filters' => ['q' => $search, 'unlinked' => $unlinked, 'inactive' => $inactive],
            'sync' => SyncMode::isFile() ? null : SyncPresenter::status(SyncKind::Clients),
        ]);
    }

    public function show(Client $client): Response
    {
        return Inertia::render('Admin/Clients/Show', [
            'client' => [
                ...$client->only([
                    'id', 'evoliz_client_id', 'evoliz_code', 'company_name', 'contact_name', 'address_line',
                    'postcode', 'town', 'phone', 'mobile', 'email', 'extra_field', 'is_active',
                ]),
                'source' => $client->source->value,
                'linked' => $client->isLinkedToEvoliz(),
                'evoliz_error' => $client->evoliz_error,
                'can_push' => self::canPush($client),
                'synced_at' => $client->synced_at?->toIso8601String(),
            ],
            'history' => $client->changes()
                ->with(['user', 'decider', 'order'])
                ->latest('id')
                ->limit(100)
                ->get()
                ->map(fn (ClientChange $change) => [
                    'id' => $change->id,
                    ...ClientChanges::present($change),
                    'source' => $change->source->value,
                    'created_at' => $change->created_at->toIso8601String(),
                    'author' => $change->user?->name,
                    'order' => $change->order !== null ? ['uuid' => $change->order->uuid, 'number' => $change->order->number] : null,
                    'can_retry' => ! SyncMode::isFile() && $change->status === ClientChangeStatus::Failed && $change->order !== null,
                ]),
        ]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        // A page opened under older names may post the optional field under its old name (App\Http\Api\ApiAliases).
        ApiAliases::accept($request, ApiAliases::client());

        if (SyncMode::isFile()) {
            return $this->updateLocally($request, $client);
        }

        $validated = $request->validate([
            'extra_field' => ['present', 'nullable', 'string', 'max:50'],
        ], [], ClientExtraField::attributes());

        $changes = ClientChanges::diff($client, ['extra_field' => $validated['extra_field']]);

        if ($changes !== []) {
            $client->update(['extra_field' => $changes['extra_field']['to']]);
            ClientChange::create([
                'client_id' => $client->id,
                'user_id' => $request->user()?->id,
                'source' => ClientChangeSource::Admin,
                'status' => ClientChangeStatus::Applied,
                'diff' => $changes,
                'applied_at' => now(),
            ]);
        }

        return back()->with('success', __(':field saved.', ['field' => ClientExtraField::label()]));
    }

    /**
     * File mode: every field, recorded in client_changes. Values checked like a
     * seller's correction, but only those the admin changes: an import may have
     * brought a foreign postcode or a phone out of format, which must not block
     * saving something else. Clearing a field is allowed here, and only here.
     */
    private function updateLocally(Request $request, Client $client): RedirectResponse
    {
        $changed = fn (string $field): bool => ClientChanges::normalise($request->input($field)) !== ClientChanges::normalise($client->getAttribute($field));

        $validated = $request->validate([
            'evoliz_code' => ['nullable', 'string', 'max:255'],
            'company_name' => ['required', 'string', 'max:120'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', ...($changed('postcode') ? ['regex:/^\d{5}$/'] : [])],
            'town' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', ...($changed('phone') ? ['max:30', new FrenchPhone] : [])],
            'mobile' => ['nullable', 'string', ...($changed('mobile') ? ['max:30', new FrenchPhone(mobile: true)] : [])],
            'email' => ['nullable', 'string', ...($changed('email') ? ['email', 'max:255'] : [])],
            'extra_field' => ['nullable', 'string', 'max:50'],
            'is_active' => ['required', 'boolean'],
        ], [], ClientExtraField::attributes());

        $code = ClientChanges::normalise($validated['evoliz_code'] ?? null);

        // The index covers imported rows only: a typed client can hold a code too.
        if ($code !== null && Client::query()->whereNull('evoliz_client_id')->whereKeyNot($client->id)->whereRaw('lower(evoliz_code) = ?', [mb_strtolower($code)])->exists()) {
            throw ValidationException::withMessages(['evoliz_code' => __('Another client already has this code.')]);
        }

        foreach (array_diff(ClientChanges::LOCAL, ['is_active']) as $field) {
            $client->setAttribute($field, ClientChanges::normalise($validated[$field] ?? null));
        }

        $client->is_active = (bool) $validated['is_active'];

        if ($client->email !== null) {
            $client->email = mb_strtolower($client->email);
        }

        DB::transaction(function () use ($client, $request) {
            ClientChanges::recordDirty($client, ClientChangeSource::Admin, $request->user()?->id);
            $client->save();
        });

        return back()->with('success', __('Client saved.'));
    }

    /**
     * Sends again a correction Evoliz refused when its order was pushed.
     */
    public function retryChange(Client $client, ClientChange $change): RedirectResponse
    {
        abort_unless($change->client_id === $client->id && $change->status === ClientChangeStatus::Failed && $change->order_id !== null, 404);

        ApplyClientChangeJob::dispatch($change->id);

        return back()->with('success', __('The correction is being sent to Evoliz again.'));
    }

    /**
     * "Envoyer à Evoliz": a client typed in the app that is not there yet, or
     * whose client-only push went wrong, without waiting for an approved order.
     */
    public function push(Client $client): RedirectResponse
    {
        abort_unless(self::canPush($client), 404);

        PushClientJob::dispatch($client->id);

        return back()->with('success', __('The client is being sent to Evoliz.'));
    }

    private static function canPush(Client $client): bool
    {
        return ! SyncMode::isFile() && $client->source === ClientSource::App && $client->is_active
            && (! $client->isLinkedToEvoliz() || $client->evoliz_error !== null);
    }

    public function sync(Request $request): RedirectResponse
    {
        SyncPresenter::dispatch(SyncKind::Clients, $request);

        return back()->with('success', __('Synchronisation started.'));
    }
}
