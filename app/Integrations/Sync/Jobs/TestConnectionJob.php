<?php

namespace App\Integrations\Sync\Jobs;

use App\Integrations\Sync\Push\PushMessages;
use App\Integrations\Sync\SyncLicence;
use App\Models\Setting;
use App\Support\EvolizState;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Passorde\Api\Contracts\EvolizApi;
use Passorde\Api\Exceptions\ApiException;
use Passorde\Licence\Exceptions\LicenceException;
use Throwable;

/**
 * "Tester la connexion" on the settings page: one read (GET /vat-rates, as
 * passorde:ping) with the saved keys, from the queue, never from the request.
 * The outcome goes to the setting evoliz.connection_test, which the page polls.
 * Nothing is called while Evoliz cannot run (keys incomplete, licence): the
 * outcome says why. Never logs a key.
 */
class TestConnectionJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const SETTING = 'evoliz.connection_test';

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(): void
    {
        $outcome = match (true) {
            ! EvolizState::enabled() => ['failed', __('Evoliz is switched off on this installation.')],
            ! EvolizState::active() => ['failed', (string) SyncLicence::unavailable()],
            default => self::ping(),
        };

        self::record($outcome[0], $outcome[1]);
    }

    public static function record(string $status, ?string $message): void
    {
        Setting::write(self::SETTING, (string) json_encode(['status' => $status, 'message' => $message, 'at' => now()->toIso8601String()]));
    }

    /**
     * @return array{status: string, message: string|null, at: string}|null
     */
    public static function last(): ?array
    {
        $value = json_decode((string) Setting::read(self::SETTING), true);

        return is_array($value) ? $value : null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function ping(): array
    {
        try {
            app(EvolizApi::class)->get('/vat-rates', ['per_page' => 1]);
        } catch (LicenceException $e) {
            return ['failed', (string) SyncLicence::refusal($e->status)];
        } catch (ApiException $e) {
            return ['failed', PushMessages::ofException($e)];
        } catch (Throwable $e) {
            report($e);

            return ['failed', __('Evoliz did not answer: :error', ['error' => class_basename($e)])];
        }

        return ['ok', __('Evoliz answers with these keys.')];
    }
}
