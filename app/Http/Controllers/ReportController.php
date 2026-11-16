<?php

namespace App\Http\Controllers;

use App\Domain\Reports\SalesReport;
use App\Domain\Reports\SalesReportExport;
use App\Domain\Reports\StockReport;
use App\Domain\Reports\StockReportExport;
use App\Domain\Reports\WriteOffReport;
use App\Domain\Reports\WriteOffReportExport;
use App\Enums\SyncKind;
use App\Enums\WriteOffType;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rapports, for every role (read-only). The stock report, one tab per category
 * group: on screen and as an Excel download.
 * The write-off report lists the broken entries and gifts of a period, 20
 * per page, filtered by type, with the same rows as an Excel download.
 * "Ventes" sums the Evoliz delivery notes of a period (SalesReport); it also
 * works offline, computed on the phone from its copy of the BLs, but its
 * Excel download needs the server. The other reports are online only: the
 * phone shows "not available offline" for them. In file mode Ventes sums the
 * exported orders, and is online only for now (no copy on the phone).
 */
class ReportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Reports/Index');
    }

    public function stock(Request $request, StockReport $report): Response
    {
        [$from, $to] = $this->period($request);

        return Inertia::render('Reports/Stock', ['report' => $report->build($from, $to)]);
    }

    public function export(Request $request, StockReport $report, StockReportExport $export): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $data = $report->build($from, $to);
        $filename = Str::slug(__('reports.file.stock')).'-'.now('Europe/Paris')->toDateString().'.xlsx';

        return response()->streamDownload(fn () => $export->write($data, 'php://output'), $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function writeOffs(Request $request, WriteOffReport $report): Response
    {
        [$from, $to, $types] = $this->writeOffFilters($request);

        return Inertia::render('Reports/WriteOffs', [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'presets' => WriteOffReport::presets(),
            'types' => array_map(fn (WriteOffType $type) => $type->value, $types),
            'totals' => $report->totals($from, $to, $types),
            'entries' => $report->page($from, $to, $types),
        ]);
    }

    public function writeOffsExport(Request $request, WriteOffReport $report, WriteOffReportExport $export): StreamedResponse
    {
        [$from, $to, $types] = $this->writeOffFilters($request);
        $rows = $report->rows($from, $to, $types);
        $filename = Str::slug(__('reports.file.write_offs')).'-'.$from->toDateString().'-'.$to->toDateString().'.xlsx';

        return response()->streamDownload(fn () => $export->write($rows, $from, $to, $types, 'php://output'), $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function sales(Request $request, SalesReport $report): Response
    {
        $filters = $this->salesFilters($request);

        return Inertia::render('Reports/Sales', [
            'filters' => $filters,
            'presets' => SalesReport::presets(),
            'sellers' => SalesReport::sellers(),
            'syncedAt' => SalesReport::fromEvoliz() ? SyncRun::lastSucceeded(SyncKind::Deliveries)?->finished_at?->toIso8601String() : null,
            // Which basis the figures have (SalesReport::fromEvoliz); offline pages leave it out and the page reads syncProvider.
            'source' => SalesReport::fromEvoliz() ? 'evoliz' : 'file',
            'report' => SalesReport::aggregate($report->input($filters)),
        ]);
    }

    public function salesExport(Request $request, SalesReport $report, SalesReportExport $export): StreamedResponse
    {
        $filters = $this->salesFilters($request);
        $input = $report->input($filters);
        $input['filters']['seller_name'] = $filters['seller_id'] !== null ? User::query()->whereKey($filters['seller_id'])->value('name') : null;
        $result = SalesReport::aggregate($input);
        $filename = 'ventes-'.$filters['from'].'-'.$filters['to'].'.xlsx';

        return response()->streamDownload(fn () => $export->write($input, $result, 'php://output'), $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Period (default: the current month), statuses (both by default) and seller.
     *
     * @return array{from: string, to: string, delivered: bool, invoiced: bool, seller_id: int|null}
     */
    private function salesFilters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'delivered' => ['nullable', 'boolean'],
            'invoiced' => ['nullable', 'boolean'],
            'seller' => ['nullable', 'integer'],
        ]);
        $month = SalesReport::presets()[2];
        $from = $validated['from'] ?? $month['from'];
        $to = $validated['to'] ?? $month['to'];

        return [
            'from' => min($from, $to),
            'to' => max($from, $to),
            'delivered' => $request->has('delivered') ? $request->boolean('delivered') : true,
            'invoiced' => $request->has('invoiced') ? $request->boolean('invoiced') : true,
            'seller_id' => isset($validated['seller']) ? (int) $validated['seller'] : null,
        ];
    }

    /**
     * The period and the types (all of them when none is given), in WriteOffType order.
     *
     * @return array{0: Carbon, 1: Carbon, 2: list<WriteOffType>}
     */
    private function writeOffFilters(Request $request): array
    {
        $validated = $request->validate([
            'types' => ['nullable', 'array'],
            'types.*' => [Rule::enum(WriteOffType::class)],
        ]);
        $selected = $validated['types'] ?? [];
        $types = array_values(array_filter(WriteOffType::cases(), fn (WriteOffType $type) => $selected === [] || in_array($type->value, $selected, true)));

        return [...$this->period($request), $types];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return StockReport::period($validated['from'] ?? null, $validated['to'] ?? null);
    }
}
