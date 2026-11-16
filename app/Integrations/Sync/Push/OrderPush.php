<?php

namespace App\Integrations\Sync\Push;

use App\Domain\Catalog\NewArticles;
use App\Domain\Clients\ClientChanges;
use App\Domain\Orders\ApprovalService;
use App\Domain\Orders\OrderTotals;
use App\Enums\ActivityAction;
use App\Enums\ClientChangeStatus;
use App\Enums\ClientSource;
use App\Enums\OrderStatus;
use App\Integrations\Sync\Sinks\ArticleSink;
use App\Integrations\Sync\Sinks\ClientSink;
use App\Integrations\Sync\SyncLicence;
use App\Models\ActivityLog;
use App\Models\ArticleRequest;
use App\Models\Client;
use App\Models\ClientChange;
use App\Models\EvolizJob;
use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Passorde\Api\Exceptions\ApiException;
use Passorde\Api\Exceptions\LoginFailedException;
use Passorde\Api\Exceptions\WritesDisabledException;
use Passorde\Drafts\ContactDraft;
use Passorde\Drafts\InvalidDraftException;
use Passorde\Licence\Exceptions\LicenceException;
use Passorde\Reads\Listing;
use Passorde\Reads\PrimaryContact;
use Passorde\Reads\ReadArticles;
use Passorde\Reads\ReadDeliveries;
use Passorde\Records\ClientRecord;
use Passorde\Records\ContactRecord;
use Passorde\Records\DeliveryRecord;
use Passorde\Writes\CreateArticle;
use Passorde\Writes\CreateClient;
use Passorde\Writes\CreateDelivery;
use Passorde\Writes\Results\AlreadyExists;
use Passorde\Writes\Results\Created;
use Passorde\Writes\Results\Failed;
use Passorde\Writes\Results\Refused;
use Passorde\Writes\Results\Updated;
use Passorde\Writes\Results\WriteResult;
use Passorde\Writes\UpdateClient;
use Passorde\Writes\UpsertContact;
use Throwable;

/**
 * An approved order to Evoliz as a registered delivery note (BL), on Passorde's verbs:
 *
 *   A. client: link the app client to Evoliz (CreateClient finds it by code or
 *      creates it), its contact once (UpsertContact), then the client correction
 *      saved with the order (UpdateClient + UpsertContact → ClientChanges::apply)
 *   B. designations of new articles and free lines not completed yet (ReadArticles::one)
 *   C. CreateDelivery: looks the BL up by external_document_number = order number
 *      before any POST, and again before any repeat
 *   D. Created or AlreadyExists: store the BL, compare totals, mark the order sent
 *
 * Every step is safe to repeat. Requests land in passorde_requests with the
 * order (or client, article request) as subject. A client saved without an
 * order goes through step A alone (pushClient). The French texts are today's.
 */
final class OrderPush
{
    /** Evoliz's refusal of the new client's contact, if any, during the current push. */
    private ?string $contactError = null;

    /** Evoliz's refusal of the order's client correction, if any, during the current push. */
    private ?string $clientChangeError = null;

    public function __construct(private readonly ApprovalService $approvals) {}

    public function push(int $orderId): void
    {
        $this->contactError = null;
        $this->clientChangeError = null;
        $order = Order::query()->with(['client', 'user', 'paytype', 'lines.article'])->find($orderId);

        if ($order === null || $order->status !== OrderStatus::Approved) {
            return;
        }

        // Exported (even once, then unmarked) while Evoliz was unavailable: its BL may be made by hand, never a second one.
        // Approved standalone: only the admin's "Envoyer à Evoliz" (ApprovalService::sendToEvoliz) sends it.
        // Downloaded in an export file and not confirmed by the admin since: never sent automatically.
        if ($order->standalone_at !== null || $order->wasEverExported() || $order->downloadUnconfirmed()) {
            return;
        }

        // Expired, invalid or missing licence: fail at once, nothing is called; "Réessayer" works once it is renewed.
        $refusal = SyncLicence::orderRefusal(SyncLicence::status());

        if ($refusal !== null) {
            $this->approvals->markFailed($order, $refusal);

            return;
        }

        try {
            Subject::run(Subject::ORDER, $order->id, fn () => $this->deliver($order));
        } catch (PushFailed $e) {
            $this->approvals->markFailed($order, $e->getMessage());
        } catch (LicenceException $e) {
            $this->approvals->markFailed($order, (string) SyncLicence::orderRefusal($e->status));
        } catch (WritesDisabledException) {
            // Test mode (PASSORDE_WRITES_ENABLED=false): the lookups ran, nothing was created.
            $this->approvals->markFailed($order, PushMessages::testMode());
        } catch (ApiException $e) {
            $this->approvals->markFailed($order, PushMessages::ofException($e));
        } catch (QueryException $e) {
            report($e);
            $this->approvals->markFailed($order, __('The Evoliz answer could not be saved (database error, see the logs). A retry is safe: the BL is looked up before any new creation.'));
        } catch (Throwable $e) {
            report($e);
            $this->approvals->markFailed($order, PushMessages::unexpected($e));
        }
    }

    private function deliver(Order $order): void
    {
        if ($order->evoliz_delivery_id !== null) {
            // Created by an earlier run that stopped before marking the order sent.
            if ($order->client->evoliz_client_id !== null) {
                $this->applyClientChange($order, $order->client->evoliz_client_id);
            }

            $record = app(ReadDeliveries::class)->one($order->evoliz_delivery_id)
                ?? throw new PushFailed(__('BL :id is no longer in Evoliz.', ['id' => $order->evoliz_delivery_id]));

            $this->finish($order, $record);

            return;
        }

        // Before anything reaches Evoliz: a BL cannot be created without a payment term.
        EvolizDrafts::paytermId();

        $clientId = $this->ensureClient($order);
        $this->applyClientChange($order, $clientId);
        $this->refreshDesignations($order);

        $result = app(CreateDelivery::class)->run(EvolizDrafts::delivery($order, $clientId));

        if ($result instanceof Refused || $result instanceof Failed) {
            throw new PushFailed(PushMessages::of($result));
        }

        // Stored at once, before anything else can fail (the sink linked the BL to the order by its number).
        $order->forceFill(['evoliz_delivery_id' => $result->record->id, 'evoliz_delivery_number' => $result->record->number])->save();

        $this->finish($order, $result->record);
    }

    /**
     * Step A alone, for a client saved without an order (PushClientJob).
     * What goes wrong stays on the client, for the admin to send it again; an
     * approved order of this client would try again by itself.
     */
    public function pushClient(int $clientId): void
    {
        $this->contactError = null;
        $client = Client::query()->find($clientId);

        if ($client === null || $client->source !== ClientSource::App || ! $client->is_active
            || ($client->isLinkedToEvoliz() && $client->evoliz_error === null)) {
            return;
        }

        try {
            Subject::run(Subject::CLIENT, $client->id, fn () => $this->linkClient($client));
            $error = $this->contactError;
        } catch (PushFailed $e) {
            $error = $e->getMessage();
        } catch (LicenceException $e) {
            $error = (string) SyncLicence::refusal($e->status);
        } catch (WritesDisabledException) {
            $error = PushMessages::testMode();
        } catch (ApiException $e) {
            $error = PushMessages::ofException($e);
        } catch (Throwable $e) {
            report($e);
            $error = PushMessages::unexpected($e);
        }

        $client->update(['evoliz_error' => $error]);
    }

    /**
     * A new article from an order. CreateArticle looks the reference up first, so
     * "Réessayer" or a reference already in Evoliz never makes a second one.
     */
    public function pushArticle(int $requestId): void
    {
        $request = ArticleRequest::query()->find($requestId);

        if ($request === null || $request->status === ArticleRequest::CREATED) {
            return;
        }

        try {
            $result = Subject::run(Subject::ARTICLE_REQUEST, $request->id, fn () => app(CreateArticle::class)->run(EvolizDrafts::article($request)));

            if ($result instanceof Created || $result instanceof AlreadyExists) {
                NewArticles::link($request, $result->record->raw);

                return;
            }

            $error = PushMessages::of($result);
        } catch (LicenceException $e) {
            $error = (string) SyncLicence::refusal($e->status);
        } catch (WritesDisabledException) {
            $error = PushMessages::testMode();
        } catch (ApiException $e) {
            $error = PushMessages::ofException($e);
        } catch (Throwable $e) {
            report($e);
            $error = PushMessages::unexpected($e);
        }

        $request->update(['status' => ArticleRequest::FAILED, 'error' => $error]);
    }

    /**
     * "Réessayer" on a correction Evoliz refused, from the client's history:
     * the order is sent already, only the correction goes again.
     */
    public function retryClientChange(int $changeId): void
    {
        $change = ClientChange::query()->with(['order', 'client'])->find($changeId);

        if ($change?->order === null || $change->status !== ClientChangeStatus::Failed || $change->client->evoliz_client_id === null) {
            return;
        }

        $this->clientChangeError = null;
        $evolizClientId = $change->client->evoliz_client_id;

        try {
            Subject::run(Subject::ORDER, $change->order->id, fn () => $this->applyClientChange($change->order, $evolizClientId));
        } catch (LicenceException $e) {
            $change->update(['error' => (string) SyncLicence::refusal($e->status)]);
        } catch (WritesDisabledException) {
            $change->update(['error' => PushMessages::testMode()]);
        } catch (ApiException $e) {
            $change->update(['error' => PushMessages::ofException($e)]);
        }
    }

    /* Step A: client */

    private function ensureClient(Order $order): int
    {
        $evolizId = $this->linkClient($order->client);

        if ($order->client->evoliz_error !== null && $this->contactError === null) {
            $order->client->update(['evoliz_error' => null]);
        }

        return $evolizId;
    }

    private function linkClient(Client $client): int
    {
        if ($client->evoliz_client_id !== null) {
            $this->createContactIfPending($client, $client->evoliz_client_id);

            return $client->evoliz_client_id;
        }

        $result = ClientSink::holding(fn () => app(CreateClient::class)->run(EvolizDrafts::client($client)));

        if ($result instanceof Refused || $result instanceof Failed) {
            throw new PushFailed(PushMessages::of($result));
        }

        $client->update([
            'evoliz_client_id' => $result->record->id,
            'evoliz_code' => $result->record->code ?? $client->evoliz_code,
            'synced_at' => now(),
        ]);
        $this->createContactIfPending($client, $result->record->id);

        return $result->record->id;
    }

    /**
     * Sends the contact once per client created by the app; one that went
     * through is never sent again. A refused contact does not fail the push:
     * the BL is still created and the order carries a warning (the next order
     * of the same client tries again).
     */
    private function createContactIfPending(Client $client, int $evolizClientId): void
    {
        $draft = EvolizDrafts::contact($client);

        if ($draft === null || $client->source !== ClientSource::App || $this->contactSent($client)) {
            return;
        }

        try {
            $result = ClientSink::holding(fn () => app(UpsertContact::class)->run($evolizClientId, $draft));
        } catch (ApiException $e) {
            if ($e instanceof LoginFailedException) {
                throw $e;
            }

            $this->contactError = PushMessages::ofException($e);

            return;
        }

        if ($result instanceof Refused || $result instanceof Failed) {
            $this->contactError = PushMessages::of($result);
        }
    }

    /**
     * A contact of this client went through already: logged in passorde_requests with
     * the client or one of its orders, or in the read-only evoliz_jobs.
     */
    private function contactSent(Client $client): bool
    {
        $orders = fn () => $client->orders()->withTrashed()->select('id');

        return DB::table('passorde_requests')
            ->whereIn('kind', ['contact.create', 'contact.update'])
            ->where('status', 'succeeded')
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('subject_type', Subject::CLIENT)->where('subject_id', $client->id))
                ->orWhere(fn ($query) => $query->where('subject_type', Subject::ORDER)->whereIn('subject_id', $orders())))
            ->exists()
            || EvolizJob::query()
                ->where('kind', 'create_contact')
                ->where('status', EvolizJob::SUCCEEDED)
                ->where(fn ($query) => $query->where('client_id', $client->id)->orWhereIn('order_id', $orders()))
                ->exists();
    }

    /**
     * Sends the client correction saved with the order, before the BL so the
     * BL carries the new details, then copies Evoliz's answer into the client.
     * UpdateClient and UpsertContact read the record back before repeating a
     * PATCH that got a 5xx (Evoliz may apply it anyway, the Evoliz connector notes in Passorde Connect "Client
     * update"). A refusal does not fail the push: the BL goes with the old
     * details and the order warns.
     */
    private function applyClientChange(Order $order, int $evolizClientId): void
    {
        $change = ClientChange::query()
            ->with('client')
            ->where('order_id', $order->id)
            ->whereIn('status', [ClientChangeStatus::Pending, ClientChangeStatus::Failed])
            ->first();

        if ($change === null) {
            return;
        }

        $values = $change->newValues();
        $evolizClient = null;
        $evolizContact = null;

        try {
            ClientSink::holding(function () use ($values, $change, $evolizClientId, &$evolizClient, &$evolizContact) {
                $patch = EvolizDrafts::clientPatch($values);
                if ($patch !== null) {
                    $evolizClient = self::written(app(UpdateClient::class)->run($evolizClientId, $patch));
                }

                if (array_key_exists('contact_name', $values) || array_key_exists('email', $values)) {
                    $draft = EvolizDrafts::contactPatch($values, $change->client, fn () => $this->primaryContact($evolizClientId));
                    $evolizContact = $draft === null ? null : self::written($this->upsertContact($evolizClientId, $draft, $change->client));
                }
            });
        } catch (PushFailed|InvalidDraftException|ApiException $e) {
            if ($e instanceof LoginFailedException) {
                throw $e;
            }

            $message = $e instanceof ApiException ? PushMessages::ofException($e) : $e->getMessage();
            ClientChanges::apply($change, $evolizClient, $evolizContact, $message);
            ActivityLog::record(ActivityAction::ClientChangeFailed, null, $order, ['error' => $message]);
            $this->clientChangeError = $message;

            return;
        }

        ClientChanges::apply($change, $evolizClient, $evolizContact);
        ActivityLog::record(ActivityAction::ClientChangeApplied, null, $order, ['fields' => ClientChanges::labels(array_keys($values))]);
    }

    /**
     * A draft without lastname cannot create a contact (Passorde throws before
     * sending anything): the client has none yet, so create it with its name.
     */
    private function upsertContact(int $evolizClientId, ContactDraft $draft, Client $client): WriteResult
    {
        try {
            return app(UpsertContact::class)->run($evolizClientId, $draft);
        } catch (InvalidDraftException $e) {
            if ($draft->canCreate()) {
                throw $e;
            }

            return app(UpsertContact::class)->run($evolizClientId, EvolizDrafts::contactToCreate($draft, $client));
        }
    }

    /**
     * The client's favourite contact, else its first enabled one: the one a
     * rename alone updates.
     */
    private function primaryContact(int $evolizClientId): ?ContactRecord
    {
        $contacts = app(Listing::class)->records('/contacts-clients', ['clientid' => $evolizClientId], ContactRecord::fromRow(...));

        return PrimaryContact::byClient($contacts)[$evolizClientId] ?? null;
    }

    /**
     * The record Evoliz holds after a write, as it returned it.
     *
     * @return array<array-key, mixed>
     */
    private static function written(WriteResult $result): array
    {
        $record = $result instanceof Created || $result instanceof Updated ? $result->record : null;

        if ($record instanceof ClientRecord || $record instanceof ContactRecord) {
            return $record->raw;
        }

        throw new PushFailed($result instanceof Refused || $result instanceof Failed ? PushMessages::of($result) : __('Evoliz did not answer.'));
    }

    /* Step B: designations */

    /**
     * Free lines and commented lines send the designation we hold, and a new
     * article's lines keep a copy from before the operator completed it. That
     * designation may not be here yet (the catalogue syncs every 15 minutes), so ask
     * Evoliz before the BL. The order's lines follow, for its page and printout.
     */
    private function refreshDesignations(Order $order): void
    {
        $order->loadMissing('lines.article');

        $newArticles = ArticleRequest::query()->whereIn('article_id', $order->lines->pluck('article_id')->filter())->pluck('article_id')->flip();

        $articles = $order->lines
            ->filter(fn (OrderLine $line) => $line->article !== null
                && ($line->is_free || $line->note !== null || $newArticles->has($line->article_id))
                && (NewArticles::lacksNumbers($line->article->designation) || NewArticles::lacksNumbers($line->designation)))
            ->map(fn (OrderLine $line) => $line->article)
            ->unique('id');

        foreach ($articles as $article) {
            if (NewArticles::lacksNumbers($article->designation) && $article->evoliz_article_id !== null) {
                $record = app(ReadArticles::class)->one($article->evoliz_article_id);

                if ($record !== null && is_string($record->raw['designation'] ?? null) && $record->raw['designation'] !== '') {
                    $article->fill(array_intersect_key(ArticleSink::map($record->raw), array_flip(['designation', 'designation_raw'])))->save();
                }
            }

            $order->lines->where('article_id', $article->id)->each(fn (OrderLine $line) => $line->update(['designation' => $article->designation]));
        }
    }

    /* Step D: finish */

    private function finish(Order $order, DeliveryRecord $delivery): void
    {
        if ($delivery->number === null || $delivery->number === '') {
            throw new PushFailed(__('Evoliz returned no delivery number.'));
        }

        $this->approvals->markSent($order, $delivery->id, $delivery->number, $this->totalsWarning($order, $delivery->raw), $this->contactError, $this->clientChangeError);
    }

    /**
     * @param  array<array-key, mixed>  $delivery  The BL as Evoliz returned it
     */
    private function totalsWarning(Order $order, array $delivery): ?string
    {
        $pairs = [
            'HT' => [$order->total_ht, data_get($delivery, 'total.vat_exclude')],
            'TVA' => [$order->total_vat, data_get($delivery, 'total.vat')],
            'TTC' => [$order->total_ttc, data_get($delivery, 'total.vat_include')],
        ];

        $differences = [];
        foreach ($pairs as $label => [$app, $evoliz]) {
            if ($evoliz === null || OrderTotals::toCents($app) !== OrderTotals::toCents($evoliz)) {
                $differences[] = __(':label app :app € / Evoliz :evoliz €', [
                    'label' => $label,
                    'app' => $app,
                    'evoliz' => $evoliz === null ? '?' : OrderTotals::money(OrderTotals::toCents($evoliz)),
                ]);
            }
        }

        return $differences === [] ? null : implode(' ; ', $differences);
    }
}
