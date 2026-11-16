<?php

// An empty value in .env reaches env() as an empty string, so defaults use ?: rather than env()'s second argument.
// Not for a switch whose default is on: env() turns false into a boolean false, which ?: would replace by the default.
// Those test for null or empty instead; a switch that is off by default can pass env() to filter_var() as it is.

// Passorde Connect. Published as config/passorde-connect.php (tag passorde-connect-config). The generic settings
// (licence, request log, licence page, writes, schedule) apply to every connector. Each connector merges its own
// section from its folder under connectors/ (passorde-connect.<connector>), set through its own variables.

// Published from marinailsapps/passorde-connect. This app's changes: an 'evoliz' section whose base_url defaults to
// Evoliz's API (as EVOLIZ_BASE_URL did; the connector's own keys merge under it), the licence page for admins only,
// the licence refresh scheduled by the app (routes/console.php). The licence store is the app's own
// (App\Integrations\Sync\Licence\HostLicenceStore, bound in AppServiceProvider): 'store' below is not read.

return [

    // The component follows the app's switch EVOLIZ_ENABLED (config/server.php): switched off, Passorde Connect
    // registers no command, route or scheduled task, so nothing mentions a licence.
    'enabled' => filter_var(env('EVOLIZ_ENABLED'), FILTER_VALIDATE_BOOL),

    // Merged over the Evoliz connector's config (connectors/evoliz/config/evoliz.php): keys here win.
    'evoliz' => [
        'base_url' => env('PASSORDE_EVOLIZ_BASE_URL') ?: 'https://www.evoliz.io',
    ],

    // Writes (any connector) stay off until they are switched on explicitly.
    'writes_enabled' => filter_var(env('PASSORDE_WRITES_ENABLED'), FILTER_VALIDATE_BOOL),

    'writes' => [
        // A verb holds a lock per delivery, article or client, so that two workers cannot create the same thing.
        'lock_seconds' => 600,
        'lock_wait_seconds' => 15,
        // The delivery lookup looks this far around both the document date and today: a date can be edited in the connected service.
        'delivery_lookup' => ['days_back' => 366, 'days_ahead' => 31],
        // After a write, the record goes to the sinks at once; a client or contact write first reads the client and its
        // contacts again for that. False skips both: the sinks wait for the next sync.
        'mirror' => in_array(env('PASSORDE_WRITES_MIRROR'), [null, ''], true) ? true : filter_var(env('PASSORDE_WRITES_MIRROR'), FILTER_VALIDATE_BOOL),
    ],

    // Where the token is cached. Empty means the application's default store.
    'cache_store' => env('PASSORDE_CACHE_STORE') ?: null,

    'request_log' => [
        // database or null. A host may also bind its own Passorde\Api\Contracts\RequestLog.
        'driver' => env('PASSORDE_REQUEST_LOG_DRIVER') ?: 'database',
    ],

    'sync' => [
        // A run that dies keeps its kind locked for this long. Rate-limit pauses count, so keep it generous.
        'lock_seconds' => 3600,
    ],

    'schedule' => [
        // Off by default: no connector reads anything until the host asks for it.
        'enabled' => filter_var(env('PASSORDE_SCHEDULE_ENABLED'), FILTER_VALIDATE_BOOL),
        // Empty means the application's timezone.
        'timezone' => env('PASSORDE_SCHEDULE_TIMEZONE') ?: null,
        // Monday to Friday, from the first hour up to but excluding the second.
        'working_hours' => [8, 19],
        // How long the scheduler's overlap mutex survives a crashed run.
        'overlap_minutes' => 30,
    ],

    'licence' => [
        // Where the licence key is kept: database (table passorde_licences, encrypted with APP_KEY) or file. A host
        // may also bind its own Passorde\Licence\Contracts\LicenceStore, for instance to keep it with its settings.
        'store' => env('PASSORDE_LICENCE_STORE') ?: 'database',
        // For the file store: one line, the key. Not to be versioned: it holds the refresh token.
        'path' => env('PASSORDE_LICENCE_PATH') ?: storage_path('passorde/licence.key'),
        // For the database store; empty means the default connection.
        'connection' => env('PASSORDE_LICENCE_CONNECTION') ?: null,
        // Empty: the licence service's own address (Passorde\Licence\LicenceService::REFRESH_URL). Set only for a test service.
        'refresh_url' => env('PASSORDE_LICENCE_REFRESH_URL') ?: null,
        // The app schedules passorde:licence refresh itself (routes/console.php: only with Evoliz switched on,
        // with an overlap lock that expires), so the package's own entry stays off.
        'refresh_enabled' => false,
        // There is no setting for extra public keys, on purpose: the trusted keys are fixed in Passorde\Licence\PublicKeys.
    ],

    'route' => [
        // False registers none of the package routes: the licence is then installed with passorde:licence install.
        'enabled' => in_array(env('PASSORDE_ROUTE_ENABLED'), [null, ''], true) ? true : filter_var(env('PASSORDE_ROUTE_ENABLED'), FILTER_VALIDATE_BOOL),
        'prefix' => env('PASSORDE_ROUTE_PREFIX') ?: 'passorde',
        // Where the licence page's "Retour" link leads: a path or a full URL of the host application.
        'back_url' => env('PASSORDE_ROUTE_BACK_URL') ?: '/',
        // Comma-separated in .env. The settings page installs licences. The default lets ANY signed-in user install
        // one: restrict it, for instance web,auth,can:manage-passorde with a Gate the host defines.
        'middleware' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) (env('PASSORDE_ROUTE_MIDDLEWARE') ?: 'web,auth,admin')),
        ))),
    ],

];
