<?php

namespace App\Http\Controllers\Api;

use App\Domain\Reports\StockReport;
use App\Http\Api\ApiAliases;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/reports/stock (docs/API.md "Reports"): the same data as the
 * The stock report page, for every role.
 */
class ReportController extends Controller
{
    public function stock(Request $request, StockReport $report): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        [$from, $to] = StockReport::period($validated['from'] ?? null, $validated['to'] ?? null);

        $built = $report->build($from, $to);
        // The old names (App\Http\Api\ApiAliases): the stock rows' category and variant, the fixed tabs' keys.
        $built['tabs'] = array_map(fn (array $tab) => [
            ...$tab,
            ...(isset(ApiAliases::stockTabs()[$tab['key']]) ? ['legacy_key' => ApiAliases::stockTabs()[$tab['key']]] : []),
            'rows' => $tab['kind'] === 'stock' ? array_map(fn (array $row) => ApiAliases::add($row, ApiAliases::article()), $tab['rows']) : $tab['rows'],
        ], $built['tabs']);

        return response()->json($built);
    }
}
