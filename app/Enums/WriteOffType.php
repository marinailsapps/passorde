<?php

namespace App\Enums;

/**
 * The kinds of stock leaving without a sale, in the write-off report
 * (reports.write_offs_title): the journal's broken entries and gifts outside
 * orders (stock_adjustments), and the free lines of delivered orders. Their
 * labels come from the profile (reports.write_off_labels).
 */
enum WriteOffType: string
{
    case Broken = 'broken';
    case OrderGift = 'order_gift';
    case Gift = 'gift';

    public function label(): string
    {
        return (string) config("passorde.reports.write_off_labels.{$this->value}");
    }
}
