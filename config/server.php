<?php

return [

    // Git SHA of the deployed build, shown in the app footer and settings page.
    'version' => env('APP_VERSION', 'dev'),

    // The one switch for the Evoliz add-on (App\Support\EvolizState): true or false. Unset or empty: off, whatever
    // else is configured (docs/RUNBOOK.md "EVOLIZ_ENABLED"). A value that is no boolean is kept as written, for
    // EvolizState::enabled() to refuse (a typo must not change where the orders go).
    'evoliz_enabled' => in_array(env('EVOLIZ_ENABLED'), [null, ''], true) ? null
        : (filter_var(env('EVOLIZ_ENABLED'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (string) env('EVOLIZ_ENABLED')),

    // First admin, created by the database seeder only when no admin exists.
    'admin' => [
        'login' => env('ADMIN_LOGIN'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    // Delivery notes created by the app.
    'evoliz' => [
        // Overrides the profile's evoliz.payterm_id (config/passorde.php) when set: the mocks' payment term (Passorde's: 37).
        'paytermid' => in_array(env('EVOLIZ_PAYTERM_ID'), [null, ''], true) ? null : (int) env('EVOLIZ_PAYTERM_ID'),
    ],

    // SMS to the client when an order reaches "sent", or is approved in file mode (OVH keys in config/services.php).
    'sms' => [
        'enabled' => (bool) env('SMS_ENABLED', false),
    ],

    // ops:check and ops:daily-digest (docs/RUNBOOK.md "Daily digest").
    'ops' => [
        // ops:check fails when the queue or scheduler heartbeat is older than this.
        'heartbeat_max_minutes' => 10,
        // Pending orders older than this are listed in the digest.
        'pending_max_days' => 2,
        // File mode: approved orders not exported after this are listed in the digest.
        'export_max_days' => 2,
        // Production always expects a nightly backup (an unconfigured bucket is
        // reported too); elsewhere only where a bucket is configured.
        'backups_expected' => env('APP_ENV') === 'production' || filled(env('BACKUP_S3_BUCKET')),
        'backup_max_age_hours' => 26,
        // Extra digest recipients (comma-separated) besides the admins with an email.
        'digest_to' => array_values(array_filter(array_map('trim', explode(',', (string) env('OPS_DIGEST_TO', ''))))),
    ],

    // Evoliz sync scheduling and retention.
    'sync' => [
        'timezone' => 'Europe/Paris',

        // The scheduled syncs (they also need Evoliz active, routes/console.php); manual runs are never gated.
        // SYNC_SCHEDULE_ENABLED decides when set; unset or empty: on in production, off anywhere else, so the
        // one switch EVOLIZ_ENABLED is enough on a server and a laptop never syncs by surprise (RUNBOOK
        // "Evoliz synchronisation").
        'schedule_enabled' => in_array(env('SYNC_SCHEDULE_ENABLED'), [null, ''], true)
            ? env('APP_ENV') === 'production'
            : filter_var(env('SYNC_SCHEDULE_ENABLED'), FILTER_VALIDATE_BOOLEAN),
    ],

];
