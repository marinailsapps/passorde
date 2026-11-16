<?php

namespace App\Domain\Orders;

use App\Domain\Clients\ClientSearch;
use App\Models\Client;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The filters of the admin orders list, shared with the export of that list:
 * author, creation period (Europe/Paris) and client search.
 */
final class OrderFilters
{
    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Order>
     */
    public static function query(array $filters): Builder
    {
        return Order::query()
            ->when($filters['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', Carbon::parse($from, 'Europe/Paris')->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', Carbon::parse($to, 'Europe/Paris')->endOfDay()->utc()))
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->whereIn('client_id', ClientSearch::rankedIds(Client::query(), $q)));
    }
}
