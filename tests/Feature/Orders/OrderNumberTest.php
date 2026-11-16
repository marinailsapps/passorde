<?php

use App\Domain\Orders\OrderNumberAllocator;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;

use function Pest\Laravel\travelTo;

it('formats numbers as C-YYYY-NNNN', function () {
    expect(OrderNumberAllocator::format(2026, 7))->toBe('C-2026-0007')
        ->and(OrderNumberAllocator::format(2026, 12345))->toBe('C-2026-12345');
});

it('counts per year, using the Paris calendar', function () {
    travelTo('2026-12-31 22:59:00'); // 23:59 in Paris
    expect(OrderNumberAllocator::next())->toBe('C-2026-0001')
        ->and(OrderNumberAllocator::next())->toBe('C-2026-0002');

    travelTo('2026-12-31 23:00:00'); // midnight in Paris
    expect(OrderNumberAllocator::next())->toBe('C-2027-0001');
});

it('never hands out the same number to concurrent transactions', function () {
    // Real parallel processes on the test database, each allocating in its own transactions.
    $year = 2099;
    $database = (string) config('database.connections.pgsql.database');
    $script = "for (\$i = 0; \$i < 25; \$i++) { echo Illuminate\\Support\\Facades\\DB::transaction(fn () => App\\Domain\\Orders\\OrderNumberAllocator::next({$year})), PHP_EOL; }";

    $run = fn (string $code) => Process::path(base_path())->env(['DB_DATABASE' => $database])->command(['php', 'artisan', 'tinker', '--execute', $code]);
    $run("DB::table('order_counters')->where('year', {$year})->delete();")->run()->throw();

    $results = Process::pool(function (Pool $pool) use ($database, $script) {
        foreach (range(1, 4) as $worker) {
            $pool->as("worker{$worker}")->path(base_path())->env(['DB_DATABASE' => $database])
                ->command(['php', 'artisan', 'tinker', '--execute', $script]);
        }
    })->start()->wait();

    $numbers = $results->collect()->flatMap(fn ($result) => preg_match_all('/C-2099-\d{4}/', $result->output(), $matches) ? $matches[0] : []);

    $run("DB::table('order_counters')->where('year', {$year})->delete();")->run();

    expect($numbers)->toHaveCount(100)
        ->and($numbers->unique())->toHaveCount(100)
        ->and($numbers->sort()->values()->first())->toBe('C-2099-0001')
        ->and($numbers->sort()->values()->last())->toBe('C-2099-0100');
});
