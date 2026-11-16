<?php

namespace App\Http\Controllers\Api;

use App\Domain\Clients\TypedClients;
use App\Domain\Orders\OrderPresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpsertClientRequest;
use App\Integrations\Sync\Jobs\PushClientJob;
use App\Models\Client;
use App\Models\User;
use App\Support\SyncMode;
use Illuminate\Http\JsonResponse;

class ClientController extends Controller
{
    /**
     * "Enregistrer sans commande": the client is saved and queued for Evoliz (saved only, in file mode).
     * Idempotent by uuid, like PUT /api/orders/{uuid}.
     */
    public function upsert(UpsertClientRequest $request, string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $exists = Client::query()->where('uuid', $uuid)->exists();
        $client = TypedClients::upsert($user, [...$request->validated(), 'uuid' => $uuid]);

        if (! SyncMode::isFile() && ! $client->isLinkedToEvoliz()) {
            PushClientJob::dispatch($client->id);
        }

        return response()->json(['client' => OrderPresenter::client($client)], $exists ? 200 : 201);
    }
}
