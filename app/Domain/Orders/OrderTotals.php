<?php

namespace App\Domain\Orders;

/**
 * Order totals in integer cents, mirroring resources/js/lib/orderTotals.ts.
 *
 * Line HT = quantity × unit price, rounded to the cent. VAT is computed once
 * per rate on the sum of that rate's HT and rounded half up, as on Evoliz
 * documents (tests/Feature/Orders/OrderTotalsTest.php).
 */
final class OrderTotals
{
    /**
     * @param  array<string, int>  $htByRate  rate ("5.50") => HT cents
     * @param  array<string, int>  $vatByRate  rate ("5.50") => VAT cents
     */
    private function __construct(
        public readonly int $quantity,
        public readonly int $htCents,
        public readonly int $vatCents,
        public readonly array $htByRate,
        public readonly array $vatByRate,
    ) {}

    /**
     * @param  iterable<array{quantity: string|int|float, unit_price_ht: string|int|float, vat_rate: string|int|float}>  $lines
     */
    public static function compute(iterable $lines): self
    {
        $quantityCents = 0;
        $htByRate = [];

        foreach ($lines as $line) {
            $quantityCents += self::toCents($line['quantity']);
            $rate = number_format((float) $line['vat_rate'], 2, '.', '');
            $htByRate[$rate] = ($htByRate[$rate] ?? 0) + self::lineHtCents($line['quantity'], $line['unit_price_ht']);
        }

        ksort($htByRate, SORT_NUMERIC);

        $vatByRate = [];
        foreach ($htByRate as $rate => $ht) {
            // rate "5.50" → 550 basis points of a percent; VAT = HT × 550 / 10000.
            $vatByRate[$rate] = (int) round($ht * self::toCents($rate) / 10000, 0, PHP_ROUND_HALF_UP);
        }

        return new self(
            quantity: $quantityCents,
            htCents: array_sum($htByRate),
            vatCents: array_sum($vatByRate),
            htByRate: $htByRate,
            vatByRate: $vatByRate,
        );
    }

    public static function lineHtCents(string|int|float $quantity, string|int|float $unitPrice): int
    {
        // Both have at most 2 decimals: (q × 100) × (p × 100) is in 1/10000 of a euro.
        return (int) round(self::toCents($quantity) * self::toCents($unitPrice) / 100, 0, PHP_ROUND_HALF_UP);
    }

    public static function toCents(string|int|float $value): int
    {
        return (int) round((float) $value * 100, 0, PHP_ROUND_HALF_UP);
    }

    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public function ttcCents(): int
    {
        return $this->htCents + $this->vatCents;
    }

    /**
     * @return list<array{rate: string, base_ht: string, vat: string}>
     */
    public function vatBreakdown(): array
    {
        $rows = [];
        foreach ($this->htByRate as $rate => $ht) {
            $rows[] = ['rate' => (string) $rate, 'base_ht' => self::money($ht), 'vat' => self::money($this->vatByRate[$rate])];
        }

        return $rows;
    }
}
