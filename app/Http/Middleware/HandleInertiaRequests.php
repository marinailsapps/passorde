<?php

namespace App\Http\Middleware;

use App\Domain\Catalog\Catalog;
use App\Domain\Catalog\NewArticles;
use App\Domain\Clients\ClientExtraField;
use App\Integrations\Sync\SyncLicence;
use App\Models\User;
use App\Support\EvolizState;
use App\Support\Profile\Wording;
use App\Support\SyncMode;
use App\Support\Turnstile;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /** Session marker: the previous page showed a one-time password. */
    private const SECRET_IN_HISTORY = 'history_has_secret';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->protectOneTimePassword($request);

        return parent::handle($request, $next);
    }

    /**
     * The page that shows a password set by an admin is stored encrypted in
     * the browser history, and the next page drops the history key, so Back
     * cannot show the password again.
     */
    private function protectOneTimePassword(Request $request): void
    {
        // Only requests that render a page; background API calls do not count.
        if (! $request->hasSession() || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
            return;
        }

        $session = $request->session();
        $showsPassword = $session->has('password');

        // Set on every page: the response factory is shared between requests in long-lived workers.
        Inertia::encryptHistory($showsPassword);

        if ($showsPassword) {
            $session->put(self::SECRET_IN_HISTORY, true);
        } elseif ($session->pull(self::SECRET_IN_HISTORY, false)) {
            Inertia::clearHistory();
        }
    }

    /**
     * Props shared by every page.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user instanceof User ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role->value,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // Plain password set by an admin, flashed once so it can be shown a single time.
                'password' => fn () => $request->session()->get('password'),
            ],
            'appVersion' => config('server.version'),
            'turnstileSiteKey' => Turnstile::siteKey(),
            // False in test mode: the layout shows "Mode test — rien n'est envoyé à Evoliz". File mode has no such thing to warn about.
            'evolizWritesEnabled' => SyncMode::isFile() || (bool) config('passorde-connect.writes_enabled'),
            // "evoliz" or "file": the pages hide what only makes sense with Evoliz (resources/js/lib/syncMode.ts).
            'syncProvider' => SyncMode::provider(),
            // Admins: file mode with a catalogue synced from Evoliz, most likely lost keys.
            'syncMismatch' => fn () => $user instanceof User && $user->isAdmin() && SyncMode::mismatch(),
            // Admins, Evoliz switched on: whether it runs, and why not (EvolizState); null otherwise.
            'evolizState' => fn () => $user instanceof User && $user->isAdmin() && EvolizState::enabled()
                ? ['active' => EvolizState::active(), 'reason' => EvolizState::reason()]
                : null,
            // Admins, Evoliz mode: the Passorde licence (resources/js/components/LicenceBanner.vue). One check per request.
            'syncLicence' => fn () => $user instanceof User && $user->isAdmin() ? SyncLicence::view(SyncLicence::status()) : null,
            // The optional client field (profile client.extra_field): {label, searchable}, or null when off.
            'clientExtraField' => ClientExtraField::view(),
            // The installation's words in the French texts and its whole sentences (App\Support\Profile\Wording).
            'wording' => Wording::view(),
            // The catalogue's variants and whether the order form has a "new article" button (catalog.*).
            'catalog' => [
                'new_article' => NewArticles::enabled(),
                // False without categories in the profile: no grouping or column by category in the reports.
                'has_categories' => Catalog::categories() !== [],
                'variants' => array_map(fn (array $variant) => [...array_intersect_key($variant, array_flip(['value', 'label', 'name', 'counts'])), 'own_tab' => $variant['group'] !== null], Catalog::variants()),
            ],
            // The approval dialogs say whether the client will get an SMS.
            'smsEnabled' => (bool) config('server.sms.enabled'),
        ];
    }
}
