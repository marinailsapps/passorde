<?php

namespace App\Domain\Orders;

use Illuminate\Support\Facades\DB;

/**
 * Allocates order numbers C-YYYY-NNNN, one sequence per year.
 *
 * The upsert takes a row lock on the year's counter, so concurrent
 * transactions queue up and never get the same number. Call it inside the
 * transaction that creates the order: a rollback gives the number back.
 */
class OrderNumberAllocator
{
    public static function next(?int $year = null): string
    {
        $year ??= (int) now('Europe/Paris')->format('Y');

        $row = DB::selectOne(
            'INSERT INTO order_counters (year, last_number) VALUES (?, 1)
             ON CONFLICT (year) DO UPDATE SET last_number = order_counters.last_number + 1
             RETURNING last_number',
            [$year],
        );

        return self::format($year, (int) $row->last_number);
    }

    public static function format(int $year, int $number): string
    {
        return sprintf('C-%d-%04d', $year, $number);
    }
}
