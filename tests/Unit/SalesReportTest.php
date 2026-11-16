<?php

use App\Domain\Reports\SalesReport;
use Illuminate\Support\Carbon;

/*
 * The sales report's aggregation, pinned together with
 * resources/js/lib/salesReport.ts (the phone's offline report).
 */
$cases = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/sales-report-cases.json'), true, flags: JSON_THROW_ON_ERROR);

it('aggregates like the phone', function (array $input, array $expected) {
    expect(SalesReport::aggregate($input))->toBe($expected);
})->with(array_combine(array_column($cases, 'name'), array_map(fn (array $case) => [$case['input'], $case['expected']], $cases)));

it('offers the current week, last week, month, quarter and year', function () {
    expect(SalesReport::presets(Carbon::parse('2026-10-22', 'Europe/Paris')))->toBe([
        ['key' => 'this_week', 'from' => '2026-10-19', 'to' => '2026-10-22'],
        ['key' => 'last_week', 'from' => '2026-10-12', 'to' => '2026-10-18'],
        ['key' => 'this_month', 'from' => '2026-10-01', 'to' => '2026-10-22'],
        ['key' => 'this_quarter', 'from' => '2026-10-01', 'to' => '2026-10-22'],
        ['key' => 'this_year', 'from' => '2026-01-01', 'to' => '2026-10-22'],
    ]);
});
