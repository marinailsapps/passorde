<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureEvolizEnabled;
use App\Http\Middleware\EnsureEvolizMode;
use App\Http\Middleware\EnsureFileMode;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Caddy: client IP and scheme come from the proxy (config/trustedproxy.php).
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->web(append: [
            EnsureUserIsActive::class,
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'sync.evoliz' => EnsureEvolizMode::class,
            'sync.file' => EnsureFileMode::class,
            'evoliz.enabled' => EnsureEvolizEnabled::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Secrets typed in the settings page never go back to the session with the old input.
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'licence_key', 'public_key', 'secret_key']);
    })->create();
