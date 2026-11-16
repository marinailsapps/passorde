<?php

use App\Domain\Orders\OrderTotals;

it('totals a whole order: paid and free lines, two rates, one of them with no base, VAT half up per rate', function () {
    // An invented order: 3 × 83.00 + 1 free + 1 × 94.00 + 2 free at 5.5 %, and 2 lines at 0 at 20 %.
    // HT 343.00; VAT 343.00 × 5.5 % = 18.865 → 18.87 (half up, once for the rate); TTC 361.87; 9 units, free ones included.
    $totals = OrderTotals::compute([
        ['quantity' => 3, 'unit_price_ht' => '83.00', 'vat_rate' => '5.5'],
        ['quantity' => 1, 'unit_price_ht' => '0.00', 'vat_rate' => '5.5'],
        ['quantity' => 1, 'unit_price_ht' => '94.00', 'vat_rate' => '5.5'],
        ['quantity' => 2, 'unit_price_ht' => '0.00', 'vat_rate' => '5.5'],
        ['quantity' => 2, 'unit_price_ht' => '0.00', 'vat_rate' => '20'],
    ]);

    expect(OrderTotals::money($totals->htCents))->toBe('343.00')
        ->and(OrderTotals::money($totals->vatCents))->toBe('18.87')
        ->and(OrderTotals::money($totals->ttcCents()))->toBe('361.87')
        ->and($totals->quantity)->toBe(900)
        ->and($totals->vatBreakdown())->toBe([
            ['rate' => '5.50', 'base_ht' => '343.00', 'vat' => '18.87'],
            ['rate' => '20.00', 'base_ht' => '0.00', 'vat' => '0.00'],
        ]);
});

it('rounds VAT once per rate, not per line', function () {
    // Per line: 0.0055 + 0.0055 → 0.01 + 0.01 = 0.02. Per rate: 0.20 × 5.5 % = 0.011 → 0.01.
    $totals = OrderTotals::compute([
        ['quantity' => 1, 'unit_price_ht' => '0.10', 'vat_rate' => '5.5'],
        ['quantity' => 1, 'unit_price_ht' => '0.10', 'vat_rate' => '5.5'],
    ]);

    expect($totals->vatCents)->toBe(1);
});

it('rounds half up', function () {
    // 0.10 × 5 % = 0.005 → 0.01; 3 × 33.33 = 99.99 × 5.5 % = 5.49945 → 5.50.
    expect(OrderTotals::compute([['quantity' => 1, 'unit_price_ht' => '0.10', 'vat_rate' => '5']])->vatCents)->toBe(1)
        ->and(OrderTotals::compute([['quantity' => 3, 'unit_price_ht' => '33.33', 'vat_rate' => '5.5']])->vatCents)->toBe(550);
});

it('multiplies decimal quantities exactly', function () {
    expect(OrderTotals::lineHtCents('2.5', '19.99'))->toBe(4998)
        ->and(OrderTotals::lineHtCents('0.33', '0.33'))->toBe(11);
});
