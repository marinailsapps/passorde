<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SyncKind;
use App\Http\Controllers\Controller;
use App\Integrations\Sync\EvolizSettings;
use App\Integrations\Sync\Jobs\TestConnectionJob;
use App\Integrations\Sync\Licence\HostLicenceStore;
use App\Integrations\Sync\Licence\InvalidLicenceKey;
use App\Integrations\Sync\Licence\LicenceInstaller;
use App\Integrations\Sync\Licence\LicenceRefused;
use App\Integrations\Sync\SyncLicence;
use App\Models\Setting;
use App\Models\User;
use App\Support\EvolizState;
use App\Support\Profile\ProfileCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Passorde\Licence\LicenceStatus;

/**
 * Settings page (admins). Its "Evoliz" block exists only with EVOLIZ_ENABLED
 *: the licence key and the three Evoliz keys, kept encrypted in
 * the database, never sent back in full; the licence status; "Tester la
 * connexion", queued. Nothing here calls Evoliz.
 */
class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Settings/Index', [
            'evoliz' => EvolizState::enabled() ? self::evoliz() : null,
            // Saved values that no longer decrypt (APP_KEY changed): they count as unset, to be entered again.
            'unreadable' => Setting::unreadable(),
            // The installation profile does not fit the database (passorde:check-profile); also in the daily digest.
            'profileErrors' => ProfileCheck::installationErrors(),
        ]);
    }

    public function updateEvoliz(Request $request, LicenceInstaller $installer): RedirectResponse
    {
        abort_unless(EvolizState::enabled(), 404);

        $validated = $request->validate([
            'licence_key' => ['nullable', 'string', 'max:16384'],
            'company_id' => ['nullable', 'string', 'regex:/^\s*\d{1,12}\s*$/'],
            'public_key' => ['nullable', 'string', 'max:255'],
            'secret_key' => ['nullable', 'string', 'max:255'],
            'clear' => ['array'],
            'clear.*' => [Rule::in(array_keys(EvolizSettings::FIELDS))],
        ]);
        /** @var User $admin */
        $admin = $request->user();
        $wasActive = EvolizState::active();

        // The key first: a refused key saves nothing, so the admin retypes nothing else.
        $keyChanged = filled($validated['licence_key'] ?? null) && trim((string) $validated['licence_key']) !== app(HostLicenceStore::class)->storedKey();
        if (filled($validated['licence_key'] ?? null)) {
            try {
                $installer->install((string) $validated['licence_key'], $admin->id);
            } catch (InvalidLicenceKey $e) {
                throw ValidationException::withMessages(['licence_key' => $e->message()]);
            } catch (LicenceRefused $e) {
                throw ValidationException::withMessages(['licence_key' => __('Licence key refused: :reason', ['reason' => $e->status->reason()])]);
            }
        }

        $fieldsChanged = EvolizSettings::save([
            'company_id' => isset($validated['company_id']) ? trim($validated['company_id']) : null,
            'public_key' => $validated['public_key'] ?? null,
            'secret_key' => $validated['secret_key'] ?? null,
        ], $validated['clear'] ?? [], $admin->id);

        // Evoliz starts now: the first syncs are queued, which adopt the rows already
        // here (Adoption). The delivery history is the RUNBOOK backfill, as on any installation.
        if (! $wasActive && EvolizState::active()) {
            foreach ([SyncKind::Paytypes, SyncKind::Articles, SyncKind::Clients] as $kind) {
                SyncPresenter::dispatch($kind, $request);
            }

            return back()->with('success', __('Evoliz settings saved. The first synchronisation has started.'));
        }

        // Every field left empty (or the same values again): say so, a mis-paste would otherwise go unnoticed.
        if (! $keyChanged && ! $fieldsChanged) {
            return back()->with('success', __('Nothing changed in the Evoliz settings.'));
        }

        return back()->with('success', __('Evoliz settings saved.'));
    }

    public function testConnection(): RedirectResponse
    {
        abort_unless(EvolizState::enabled(), 404);

        TestConnectionJob::record('pending', null);
        TestConnectionJob::dispatch();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private static function evoliz(): array
    {
        $status = SyncLicence::status();
        $store = app(HostLicenceStore::class);
        $key = $store->storedKey();
        $licence = $status?->licence;

        return [
            'active' => EvolizState::active(),
            'reason' => EvolizState::reason(),
            'fields' => EvolizSettings::view(),
            'licence' => [
                ...(SyncLicence::view($status) ?? []),
                // Where the licence comes from: the key saved here, or the file of an older installation.
                'source' => $key !== null ? 'database' : ($store->current() !== null ? 'file' : null),
                // The licence number (on the certificate) tells two keys apart; the key itself is never shown.
                'licence_id' => $licence?->licenceId,
                'licensee' => $licence?->company,
                'plan' => $licence?->plan,
                // In the words of the licence e-mails ("abonnement 3 mois"); an unknown code as it is.
                'plan_label' => self::planLabel($licence?->plan),
                // Lifetime keys: the end of their updates; null for the others.
                'updates_until' => $licence?->updatesUntil,
            ],
            'test' => TestConnectionJob::last(),
            'missing' => self::missing($status),
        ];
    }

    private static function planLabel(?string $plan): ?string
    {
        if ($plan === null || $plan === '') {
            return null;
        }
        $key = "licence.plan.{$plan}";

        return __($key) !== $key ? __($key) : $plan;
    }

    /**
     * What the admin still has to enter, in plain French.
     *
     * @return list<string>
     */
    private static function missing(?LicenceStatus $status): array
    {
        $labels = ['company_id' => __('Evoliz company number'), 'public_key' => __('Evoliz public key'), 'secret_key' => __('Evoliz secret key')];
        $missing = [];

        if ($status === null || ! $status->isUsable()) {
            $missing[] = __('Licence key: :reason', ['reason' => $status?->reason() ?? '']);
        }

        foreach (EvolizSettings::view() as $field => $view) {
            if (! $view['set']) {
                $missing[] = __(':field: missing', ['field' => $labels[$field]]);
            }
        }

        return $missing;
    }
}
