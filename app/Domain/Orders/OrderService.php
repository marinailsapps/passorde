<?php

namespace App\Domain\Orders;

use App\Domain\Clients\ClientChanges;
use App\Domain\Clients\DuplicateFinder;
use App\Domain\Clients\TypedClients;
use App\Enums\ActivityAction;
use App\Enums\OrderStatus;
use App\Models\ActivityLog;
use App\Models\Article;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Creates and updates orders by their client-generated uuid. Saving the same
 * payload twice is harmless (the outbox replays requests), and the
 * server always recomputes designations, VAT and totals from the catalog.
 */
class OrderService
{
    /**
     * @param  array<string, mixed>  $data  Validated UpsertOrderRequest payload
     * @return array{order: Order, created: bool, possible_duplicate: Client|null}
     */
    public function save(User $user, string $uuid, array $data): array
    {
        try {
            return DB::transaction(fn () => $this->saveInTransaction($user, $uuid, $data));
        } catch (UniqueConstraintViolationException) {
            // Two requests with a new uuid raced; the other one created the order. Update it.
            return DB::transaction(fn () => $this->saveInTransaction($user, $uuid, $data));
        }
    }

    public function delete(User $user, Order $order): void
    {
        DB::transaction(function () use ($user, $order) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! ($user->isAdmin() && $order->status === OrderStatus::Rejected)) {
                $this->assertAuthor($user, $order);
                $this->assertStatus($order, [OrderStatus::Pending, OrderStatus::Rejected]);
            }

            $order->delete();
            ClientChanges::cancelForOrder($order, $user);
            ActivityLog::record(ActivityAction::Deleted, $user, $order, ['number' => $order->number]);
        });
    }

    private function assertAuthor(User $user, Order $order): void
    {
        if ($order->user_id !== $user->id) {
            throw new AccessDeniedHttpException(__('This order belongs to another user.'));
        }
    }

    /**
     * @param  list<OrderStatus>  $statuses  rejected orders can be deleted, not edited
     */
    private function assertStatus(Order $order, array $statuses = [OrderStatus::Pending]): void
    {
        if ($order->trashed()) {
            throw new ConflictHttpException(__('This order has been deleted.'));
        }

        if (! in_array($order->status, $statuses, true)) {
            throw new ConflictHttpException(__('This order can no longer be changed: its status is ":status".', [
                'status' => __('order.status.'.$order->status->value),
            ]));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{order: Order, created: bool, possible_duplicate: Client|null}
     */
    private function saveInTransaction(User $user, string $uuid, array $data): array
    {
        $order = Order::withTrashed()->where('uuid', $uuid)->lockForUpdate()->first();

        if ($order !== null) {
            // The operator corrects sellers' orders; the order stays its author's.
            if (! $user->isAdmin()) {
                $this->assertAuthor($user, $order);
            }

            $this->assertStatus($order);
        }

        [$client, $isNewClient] = $this->resolveClient($user, $data);
        $lines = $this->buildLines($data['lines']);
        $totals = OrderTotals::compute($lines);

        $created = $order === null;
        $before = $created ? null : $this->fingerprint($order);
        $snapshot = $created ? null : OrderChanges::snapshot($order);
        $previousClientId = $order?->client_id;

        $order ??= new Order([
            'uuid' => $uuid,
            'user_id' => $user->id,
            'number' => OrderNumberAllocator::next(),
            'status' => OrderStatus::Pending,
        ]);

        $order->fill([
            'client_id' => $client->id,
            'paytype_id' => $data['paytype_id'] ?? null,
            'comment' => $data['comment'] ?? null,
            'total_quantity' => OrderTotals::money($totals->quantity),
            'total_ht' => OrderTotals::money($totals->htCents),
            'total_vat' => OrderTotals::money($totals->vatCents),
            'total_ttc' => OrderTotals::money($totals->ttcCents()),
        ])->save();

        $order->lines()->delete();
        $order->lines()->createMany($lines);
        $order->load('lines');
        $order->setRelation('client', $client);
        ClientChanges::saveForOrder($order, $user, $data['client_changes'] ?? null);

        if ($created) {
            ActivityLog::record(ActivityAction::Created, $user, $order, ['number' => $order->number]);

            if (! empty($data['duplicated_from'])) {
                $source = Order::withTrashed()->where('uuid', $data['duplicated_from'])->first();
                ActivityLog::record(ActivityAction::Duplicated, $user, $order, [
                    'from_uuid' => $data['duplicated_from'],
                    'from_number' => $source?->number,
                ]);
            }
        } elseif ($before !== $this->fingerprint($order)) {
            // Lines and corrections live in other tables: the author's phone follows updated_at.
            $order->touch();
            ActivityLog::record(ActivityAction::Updated, $user, $order, [
                'number' => $order->number,
                'before' => $snapshot,
                'after' => OrderChanges::snapshot($order),
            ]);
        }

        if ($previousClientId !== null && $previousClientId !== $client->id) {
            $this->deleteOrphanAppClient($user, $previousClientId);
        }

        return [
            'order' => $order,
            'created' => $created,
            'possible_duplicate' => $isNewClient
                ? DuplicateFinder::find($client->company_name, $client->postcode, $client->id)
                : null,
        ];
    }

    /**
     * An existing client by id, or the client typed in the order (found again by its uuid).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: Client, 1: bool} The client and whether it is an app-created, not yet settled client
     */
    private function resolveClient(User $user, array $data): array
    {
        if (! empty($data['client_id'])) {
            $client = Client::query()->findOrFail($data['client_id']);

            return [$client, $client->isOpenTypedClient()];
        }

        /** @var array<string, mixed> $fields */
        $fields = $data['client'];
        $client = TypedClients::upsert($user, $fields);

        return [$client, $client->isOpenTypedClient()];
    }

    /**
     * Snapshot lines from the catalog: designation and VAT always come from the
     * article; free lines are forced to 0 €. Positions follow the given order.
     *
     * @param  list<array<string, mixed>>  $input
     * @return list<array<string, mixed>>
     */
    private function buildLines(array $input): array
    {
        usort($input, fn (array $a, array $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));
        $articles = Article::query()->whereIn('id', array_column($input, 'article_id'))->get()->keyBy('id');

        $lines = [];
        foreach ($input as $index => $line) {
            /** @var Article $article */
            $article = $articles->get($line['article_id']);
            $isFree = (bool) ($line['is_free'] ?? false);
            $unitPrice = $isFree ? '0.00' : number_format((float) $line['unit_price_ht'], 2, '.', '');
            $quantity = number_format((float) $line['quantity'], 2, '.', '');

            $lines[] = [
                'position' => $index,
                'article_id' => $article->id,
                'designation' => $article->designation,
                'note' => trim((string) ($line['note'] ?? '')) ?: null,
                'quantity' => $quantity,
                'unit_price_ht' => $unitPrice,
                'vat_rate' => $article->vat_rate,
                'is_free' => $isFree,
                'total_ht' => OrderTotals::money(OrderTotals::lineHtCents($quantity, $unitPrice)),
            ];
        }

        return $lines;
    }

    /**
     * Drop a client typed for this order and then replaced (e.g. by picking the
     * suggested existing client), if nothing else refers to it: the user's own,
     * or any when the operator corrects a seller's order.
     */
    private function deleteOrphanAppClient(User $user, int $clientId): void
    {
        $client = Client::query()->find($clientId);

        if ($client !== null
            && $client->isOpenTypedClient()
            && ($client->created_by === $user->id || $user->isAdmin())
            && ! Order::withTrashed()->where('client_id', $client->id)->exists()) {
            $client->delete();
        }
    }

    private function fingerprint(Order $order): string
    {
        return (string) json_encode([
            $order->client_id,
            $order->paytype_id,
            $order->comment,
            $order->lines()->get(['position', 'article_id', 'quantity', 'unit_price_ht', 'is_free', 'note'])->toArray(),
            $order->clientChange()->value('diff'),
        ]);
    }
}
