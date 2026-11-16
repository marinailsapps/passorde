<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The offline app shell: the root HTML with the built assets and no user data,
 * precached by the service worker (resources/js/sw.ts). When a navigation
 * cannot reach the server, the service worker answers with this page and the
 * app renders the requested route from the phone's local data.
 */
class AppShellController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return response()->view('app', [
            'page' => [
                'component' => 'Offline/Shell',
                'props' => ['errors' => (object) []],
                'url' => '/app-shell',
                'version' => Inertia::getVersion(),
                'clearHistory' => false,
                'encryptHistory' => false,
            ],
        ]);
    }

    /**
     * The web app manifest, built by vite-plugin-pwa into public/build and
     * served at the root so its URL sits within the service worker's scope.
     */
    public function manifest(): BinaryFileResponse
    {
        $path = public_path('build/manifest.webmanifest');
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'application/manifest+json']);
    }
}
