<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Orders\OrderExport;
use App\Domain\Orders\OrderFilters;
use App\Enums\ActivityAction;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * File mode: the file the operator retypes, for the orders ticked on the list
 * (uuids) or the list's filters. Taking the file moves no stock and changes no
 * status: marking the orders exported is a separate step. Each order in the file
 * gets an "export_downloaded" activity entry once the server has written the
 * whole file (a download that fails while it is written records nothing): an
 * order downloaded but never marked is then sent to Evoliz only on the admin's
 * explicit confirmation (ApprovalService::sendToEvoliz).
 */
class OrderExportController extends Controller
{
    public function csv(Request $request, OrderExport $export): StreamedResponse
    {
        $orders = $this->orders($request);
        $rows = $export->rows($orders);
        $admin = $request->user();

        return response()->streamDownload(function () use ($export, $rows, $orders, $admin) {
            $export->writeCsv($rows, 'php://output');
            self::recordDownload($orders, $admin, 'csv');
        }, $this->filename('csv'), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function xlsx(Request $request, OrderExport $export): StreamedResponse
    {
        $orders = $this->orders($request);
        $rows = $export->rows($orders);
        $admin = $request->user();

        return response()->streamDownload(function () use ($export, $rows, $orders, $admin) {
            $export->writeXlsx($rows, 'php://output');
            self::recordDownload($orders, $admin, 'xlsx');
        }, $this->filename('xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Approved orders only, whichever way they are asked for; 404 when there
     * are none, so that an empty download never leads to a "mark as exported".
     *
     * @return iterable<Order>
     */
    private function orders(Request $request): iterable
    {
        $filters = $request->validate([
            'uuids' => ['nullable', 'array', 'max:'.OrderExport::MAX_ORDERS],
            'uuids.*' => ['required', 'uuid'],
            'status' => ['nullable', Rule::in(['to_export', 'exported'])],
            ...OrderFilters::rules(),
        ]);

        $orders = $this->query($filters)->with(['user', 'client', 'paytype', 'lines.article'])->orderBy('id')->limit(OrderExport::MAX_ORDERS)->get();
        abort_if($orders->isEmpty(), 404);

        return $orders;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Order>
     */
    private function query(array $filters): Builder
    {
        if (! empty($filters['uuids'])) {
            return Order::query()->whereIn('uuid', $filters['uuids'])->where('status', OrderStatus::Approved);
        }

        $query = OrderFilters::query($filters);

        return ($filters['status'] ?? 'to_export') === 'exported' ? $query->exported() : $query->toExport();
    }

    /**
     * After the whole file is written: each order in it was handed out for retyping.
     *
     * @param  iterable<Order>  $orders
     */
    private static function recordDownload(iterable $orders, mixed $admin, string $format): void
    {
        foreach ($orders as $order) {
            ActivityLog::record(ActivityAction::ExportDownloaded, $admin instanceof User ? $admin : null, $order, ['number' => $order->number, 'format' => $format]);
        }
    }

    private function filename(string $extension): string
    {
        return 'commandes-'.now('Europe/Paris')->format('Y-m-d-His').'.'.$extension;
    }
}
