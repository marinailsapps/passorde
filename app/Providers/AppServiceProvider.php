<?php

namespace App\Providers;

use App\Integrations\Ovh\OvhSmsClient;
use App\Integrations\Sync\EvolizSettings;
use App\Integrations\Sync\FileSyncProvider;
use App\Integrations\Sync\Licence\HostLicenceStore;
use App\Integrations\Sync\ModeAwareEvolizApi;
use App\Integrations\Sync\PassordeProvider;
use App\Integrations\Sync\Sinks\ArticleSink;
use App\Integrations\Sync\Sinks\ClientSink;
use App\Integrations\Sync\Sinks\DeliverySink;
use App\Integrations\Sync\Sinks\PaytypeSink;
use App\Integrations\Sync\Sinks\UserSink;
use App\Integrations\Sync\SyncProvider;
use App\Models\Setting;
use App\Support\EvolizState;
use App\Support\Profile\ProfileTranslationLoader;
use App\Support\SyncMode;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Passorde\Api\Contracts\EvolizApi;
use Passorde\Licence\Contracts\LicenceStore;
use Passorde\Sinks\Contracts;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The installation's wording over lang/fr.json (App\Support\Profile\Wording).
        $this->app->extend('translation.loader', fn (Loader $loader) => new ProfileTranslationLoader($loader));

        // The mode decides at resolve time, so that a test switching to file mode gets the file provider.
        $this->app->bind(SyncProvider::class, fn (Application $app) => SyncMode::isFile()
            ? $app->make(FileSyncProvider::class)
            : $app->make(PassordeProvider::class));

        // Passorde's runners write into the app's own tables, never the passorde_ mirror.
        $this->app->bind(Contracts\ArticleSink::class, ArticleSink::class);
        $this->app->bind(Contracts\ClientSink::class, ClientSink::class);
        $this->app->bind(Contracts\PaytypeSink::class, PaytypeSink::class);
        $this->app->bind(Contracts\UserSink::class, UserSink::class);
        $this->app->bind(Contracts\DeliverySink::class, DeliverySink::class);

        // File-mode tripwire on Passorde's transport: any call throws before anything is sent. The mode is
        // asked at every call, not at the first resolve, so a long-running worker follows a lapse or a key removal.
        $this->app->singleton(EvolizApi::class, fn (Application $app) => new ModeAwareEvolizApi($app));

        // Passorde's pages (licence page) exist only with Evoliz switched on. Every register() runs before
        // Passorde's boot() registers its routes, so the middleware list is complete by then.
        config()->set('passorde-connect.route.middleware', array_values(array_unique([...(array) config('passorde-connect.route.middleware'), 'evoliz.enabled'])));

        // The licence lives in the database once an admin entered it, in Passorde's file until then.
        $this->app->extend(LicenceStore::class, fn () => new HostLicenceStore);

        $this->app->bind(OvhSmsClient::class, fn (Application $app) => new OvhSmsClient(
            http: $app->make(HttpFactory::class),
            endpoint: rtrim((string) config('services.ovh.endpoint'), '/'),
            appKey: (string) config('services.ovh.app_key'),
            appSecret: (string) config('services.ovh.app_secret'),
            consumerKey: (string) config('services.ovh.consumer_key'),
            service: (string) config('services.ovh.sms_service'),
            sender: config('services.ovh.sms_sender'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Production sits behind Caddy (TLS); links in emails and redirects must be https.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // Evoliz keys saved in the settings page win over .env; a worker re-reads them before each job.
        EvolizSettings::apply();
        Queue::before(function () {
            Setting::flush();
            EvolizSettings::apply();
            EvolizState::flush();
        });

        // New passwords only (creation, change, reset); existing ones keep working.
        Password::defaults(fn () => Password::min(12)->uncompromised());
    }
}
