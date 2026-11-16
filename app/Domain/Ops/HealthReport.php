<?php

namespace App\Domain\Ops;

use App\Enums\EvolizStockStatus;
use App\Enums\OrderStatus;
use App\Enums\SmsStatus;
use App\Enums\SyncStatus;
use App\Integrations\Sync\EvolizSettings;
use App\Integrations\Sync\SyncLicence;
use App\Models\Client;
use App\Models\OpsHeartbeat;
use App\Models\Order;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\StockAdjustment;
use App\Models\SyncRun;
use App\Support\EvolizState;
use App\Support\Profile\ProfileCheck;
use App\Support\SyncMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Passorde\Licence\LicenceState;

/**
 * What ops:daily-digest reports: everything that needs the admin's attention.
 * Each section is a French title and its lines; an empty report means all is well.
 */
class HealthReport
{
    private const MAX_LINES = 10;

    /**
     * @return list<array{title: string, lines: list<string>}>
     */
    public function sections(): array
    {
        return array_values(array_filter([
            $this->profile(),
            $this->syncModeMismatch(),
            $this->unreadableSettings(),
            $this->evolizKeys(),
            $this->licence(),
            $this->failedPushes(),
            $this->failedSyncs(),
            $this->failedJobs(),
            $this->stalePendingOrders(),
            $this->unexportedOrders(),
            $this->backup(),
            $this->heartbeats(),
            $this->failedSms(),
            $this->failedStockAdjustments(),
            $this->failedClients(),
        ]));
    }

    /**
     * The installation profile has errors (passorde:check-profile): categories or texts may be wrong.
     *
     * @return array{title: string, lines: list<string>}|null
     */
    private function profile(): ?array
    {
        $errors = ProfileCheck::installationErrors();

        return $this->section(__('Installation profile (:count)', ['count' => count($errors)]), $errors);
    }

    /**
     * File mode with an Evoliz catalogue: most likely lost keys, and no BL is being created.
     *
     * @return array{title: string, lines: list<string>}|null
     */
    private function syncModeMismatch(): ?array
    {
        return $this->section(__('Sync mode'), SyncMode::mismatch()
            ? [__('File mode, with a catalogue from Evoliz: approved orders are not sent to Evoliz. Check EVOLIZ_ENABLED and the Evoliz keys.')]
            : []);
    }

    /**
     * Settings saved in the app that no longer decrypt (APP_KEY changed): they read as unset.
     *
     * @return array{title: string, lines: list<string>}|null
     */
    private function unreadableSettings(): ?array
    {
        $keys = Setting::unreadable();

        return $this->section(__('Unreadable settings (:count)', ['count' => count($keys)]), $keys === [] ? [] : [
            __('These values saved on the settings pages can no longer be decrypted (APP_KEY changed?) and count as not set: :keys. Enter them again.', ['keys' => implode(', ', $keys)]),
        ]);
    }

    /**
     * Evoliz switched on, its keys incomplete: nothing reaches Evoliz until they are entered.
     *
     * @return array{title: string, lines: list<string>}|null
     */
    private function evolizKeys(): ?array
    {
        if (! EvolizState::enabled() || EvolizState::credentialsComplete()) {
            return $this->section(__('Evoliz keys'), []);
        }

        $labels = ['company_id' => __('Evoliz company number'), 'public_key' => __('Evoliz public key'), 'secret_key' => __('Evoliz secret key')];
        $missing = array_keys(array_filter(EvolizSettings::view(), fn (array $view) => ! $view['set']));

        return $this->section(__('Evoliz keys'), [
            __('Evoliz is switched on but its keys are incomplete (:missing): nothing is sent to Evoliz, the app runs on its own. Enter them in Evoliz settings.', [
                'missing' => implode(', ', array_map(fn (string $field) => $labels[$field], $missing)),
            ]),
        ]);
    }

    /**
     * The Passorde licence in grace, expired or invalid (none in file mode).
     *
     * @return array{title: string, lines: list<string>}|null
     */
    private function licence(): ?array
    {
        $status = SyncLicence::status();

        return $this->section(__('Passorde licence'), match (true) {
            $status === null, $status->state === LicenceState::Valid => [],
            $status->state === LicenceState::Grace => [$status->reason(), __('Everything still runs during the grace period.')],
            default => [$status->reason(), __('Nothing is sent to Evoliz and no synchronisation runs; the app runs on its own (approved orders are exported) until a valid licence is installed.')],
        });
    }

    /**
     * File mode: an approved order is only done once it is exported.
     *
     * @return array{title: string, lines: list<string>}|null
     */
    private function unexportedOrders(): ?array
    {
        if (! SyncMode::isFile()) {
            return null;
        }

        $days = (int) config('server.ops.export_max_days');
        $orders = Order::query()->with(['client', 'user'])->where('status', OrderStatus::Approved)->whereNull('exported_at')
            ->where('approved_at', '<', now()->subDays($days))->orderBy('approved_at')->get();

        return $this->section(__('Orders approved but not exported for more than :days days (:count)', ['days' => $days, 'count' => $orders->count()]), $orders->map(
            fn (Order $order) => "{$order->number} · {$order->client->company_name} · {$order->user->name} · ".self::time($order->approved_at ?? $order->created_at),
        )->all());
    }

    /**
     * @return array{title: string, lines: list<string>}|null
     */
    private function failedPushes(): ?array
    {
        $orders = Order::query()->with('client')->where('status', OrderStatus::Failed)->orderBy('id')->get();

        return $this->section(__('Orders whose push to Evoliz failed (:count)', ['count' => $orders->count()]), $orders->map(
            fn (Order $order) => "{$order->number} · {$order->client->company_name} · ".Str::limit((string) $order->evoliz_error, 160),
        )->all());
    }

    /**
     * @return array{title: string, lines: list<string>}|null
     */
    private function failedClients(): ?array
    {
        $clients = Client::query()->whereNotNull('evoliz_error')->orderBy('id')->get();

        return $this->section(__('Clients Evoliz refused (:count)', ['count' => $clients->count()]), $clients->map(
            fn (Client $client) => "{$client->company_name} · ".Str::limit((string) $client->evoliz_error, 160),
        )->all());
    }

    /**
     * @return array{title: string, lines: list<string>}|null
     */
    private function failedSyncs(): ?array
    {
        $runs = SyncRun::query()->where('status', SyncStatus::Failed)->where('created_at', '>=', now()->subDay())->orderBy('id')->get();

        return $this->section(__('Evoliz synchronisations failed in the last 24 hours (:count)', ['count' => $runs->count()]), $runs->map(
            fn (SyncRun $run) => self::time($run->created_at).' · '.__('kind.'.$run->kind->value).' · '.Str::limit((string) $run->error, 160),
        )->all());
    }

    /**
     * @return array{title: string, lines: list<string>}|null
     */
    private function failedJobs(): ?array
    {
        $jobs = DB::table('failed_jobs')->orderByDesc('id')->get(['payload', 'exception', 'failed_at']);

        return $this->section(__('Failed background jobs (:count)', ['count' => $jobs->count()]), $jobs->map(function (object $job) {
            $name = json_decode((string) $job->payload, true)['displayName'] ?? '?';

            return self::time(Carbon::parse((string) $job->failed_at)).' · '.class_basename((string) $name).' · '.Str::limit(strtok((string) $job->exception, "\n") ?: '', 160);
        })->all());
    }

    /**
     * @return array{title: string, lines: list<string>}|null
     */
    private function stalePendingOrders(): ?array
    {
        $days = (int) config('server.ops.pending_max_days');
        $orders = Order::query()->with(['client', 'user'])->where('status', OrderStatus::Pending)
            ->where('created_at', '<', now()->subDays($days))->orderBy('created_at')->get();

        return $this->section(__('Orders pending for more than :days days (:count)', ['days' => $days, 'count' => $orders->count()]), $orders->map(
            fn (Order $order) => "{$order->number} · {$order->client->company_name} · {$order->user->name} · ".self::time($order->created_at),
        )->all());
    }

    /**
     * @return array{title: string, lines: list<string>}|null
     */
    private function backup(): ?array
    {
        if (! config('server.ops.backups_expected')) {
            return null;
        }

        $maxHours = (int) config('server.ops.backup_max_age_hours');
        $backup = OpsHeartbeat::query()->find(OpsHeartbeat::BACKUP);
        $lines = [];

        if ($backup?->last_ok_at === null) {
            $lines[] = __('No successful backup recorded.');
        } elseif ($backup->last_ok_at->lt(now()->subHours($maxHours))) {
            $lines[] = __('Last successful backup: :time (more than :hours hours ago).', ['time' => self::time($backup->last_ok_at), 'hours' => $maxHours]);
        }

        if ($backup?->last_failed_at !== null && ($backup->last_ok_at === null || $backup->last_failed_at->gt($backup->last_ok_at))) {
            $lines[] = __('Last attempt failed at :time: :error', ['time' => self::time($backup->last_failed_at), 'error' => Str::limit((string) $backup->last_error, 300)]);
        }

        return $this->section(__('Backups'), $lines);
    }

    /**
     * @return array{title: string, lines: list<string>}|null
     */
    private function heartbeats(): ?array
    {
        $maxMinutes = (int) config('server.ops.heartbeat_max_minutes');
        $lines = [];

        foreach ([OpsHeartbeat::QUEUE => __('Queue worker'), OpsHeartbeat::SCHEDULER => __('Scheduler')] as $name => $label) {
            $age = OpsHeartbeat::ageInMinutes($name);

            if ($age === null || $age > $maxMinutes) {
                $lines[] = $age === null
                    ? __(':part: no heartbeat recorded.', ['part' => $label])
                    : __(':part: last heartbeat :minutes min ago.', ['part' => $label, 'minutes' => (int) $age]);
            }
        }

        return $this->section(__('Background services'), $lines);
    }

    /**
     * @return array{title: string, lines: list<string>}|null
     */
    private function failedSms(): ?array
    {
        $logs = SmsLog::query()->with('order')->where('status', SmsStatus::Failed)->where('created_at', '>=', now()->subDay())->orderBy('id')->get();

        return $this->section(__('Client SMS that failed in the last 24 hours (:count)', ['count' => $logs->count()]), $logs->map(
            fn (SmsLog $log) => "{$log->order->number} · {$log->phone} · ".Str::limit((string) $log->error, 160),
        )->all());
    }

    /**
     * Broken entries / gifts whose Evoliz stock update failed (deduction or add-back).
     *
     * @return array{title: string, lines: list<string>}|null
     */
    private function failedStockAdjustments(): ?array
    {
        $entries = StockAdjustment::withTrashed()->with('article')->where('evoliz_status', EvolizStockStatus::Failed)->orderBy('id')->get()
            ->filter(fn (StockAdjustment $entry) => $entry->evolizOutOfStep());

        return $this->section(__('Broken entries / gifts not applied to the Evoliz stock (:count)', ['count' => $entries->count()]), $entries->map(
            fn (StockAdjustment $entry) => $entry->happened_on->format('d/m/Y').' · '.$entry->article->reference.' × '.rtrim(rtrim($entry->quantity, '0'), '.')
                .($entry->trashed() ? ' · '.__('deleted, to give back') : '').' · '.Str::limit((string) $entry->evoliz_error, 160),
        )->values()->all());
    }

    /**
     * @param  list<string>  $lines
     * @return array{title: string, lines: list<string>}|null
     */
    private function section(string $title, array $lines): ?array
    {
        if ($lines === []) {
            return null;
        }

        $more = count($lines) - self::MAX_LINES;
        $lines = array_slice($lines, 0, self::MAX_LINES);

        if ($more > 0) {
            $lines[] = __('… and :count more', ['count' => $more]);
        }

        return ['title' => $title, 'lines' => $lines];
    }

    private static function time(Carbon $at): string
    {
        return $at->copy()->timezone('Europe/Paris')->format('d/m/Y H:i');
    }
}
