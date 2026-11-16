<?php

namespace App\Http\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/**
 * Older API names an installation still answers to (the profile's `aliases`, config/passorde.php; docs/API.md
 * "Old names"). Every map is empty unless the installation's profile states one: a new installation has no older
 * names.
 *
 * Both ways: a request may carry an old name instead of the current one (a phone's unsent outbox entry, an old
 * cached page), and an answer carries the old name beside the current one, same value, so an older copy of the app
 * keeps reading what it reads.
 */
final class ApiAliases
{
    /**
     * A client object: current => old.
     *
     * @return array<string, string>
     */
    public static function client(): array
    {
        return self::map('client');
    }

    /**
     * An article of the mirror, a stock report row, a new-article request: current => old.
     *
     * @return array<string, string>
     */
    public static function article(): array
    {
        return self::map('article');
    }

    /**
     * The stock report's fixed tabs: key => old key, answered as `legacy_key` (a value, not a field name).
     *
     * @return array<string, string>
     */
    public static function stockTabs(): array
    {
        return self::map('stock_tabs');
    }

    /**
     * An order's totals: current => old.
     *
     * @return array<string, string>
     */
    public static function totals(): array
    {
        return self::map('totals');
    }

    /**
     * An order summary of GET /api/orders: current => old.
     *
     * @return array<string, string>
     */
    public static function summary(): array
    {
        return self::map('summary');
    }

    /**
     * Every old field name, whatever the object.
     *
     * @return list<string>
     */
    public static function oldNames(): array
    {
        return array_values([...self::client(), ...self::article(), ...self::totals(), ...self::summary()]);
    }

    /**
     * The answer side: each old name right after its current one, with the same value.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $map  current => old
     * @return array<string, mixed>
     */
    public static function add(array $data, array $map): array
    {
        if ($map === []) {
            return $data;
        }

        $out = [];
        foreach ($data as $key => $value) {
            $out[$key] = $value;
            if (isset($map[$key])) {
                $out[$map[$key]] = $value;
            }
        }

        return $out;
    }

    /**
     * The request side: an old name stands for the current one when the current one is absent (the current one wins
     * when both are sent). Presence is kept: an old name sent as null becomes the current name sent as null.
     *
     * @param  array<string, string>  $map  current => old
     * @param  string|null  $under  the request key that holds the object (e.g. "client"); null: the body itself
     */
    public static function accept(Request $request, array $map, ?string $under = null): void
    {
        $values = $under === null ? $request->all() : $request->input($under);

        if ($map === [] || ! is_array($values)) {
            return;
        }

        $touched = false;
        foreach ($map as $new => $old) {
            if (! array_key_exists($old, $values)) {
                continue;
            }
            $touched = true;
            if (! array_key_exists($new, $values)) {
                $values[$new] = $values[$old];
            }
            unset($values[$old]);
        }

        if ($touched) {
            $request->replace($under === null ? $values : [...$request->all(), $under => $values]);
        }
    }

    /**
     * The old addresses of the profile (`aliases.routes.api` and `.web`, full paths: old => current), registered
     * with the current route's action and middleware: each method of the current route answers at the old path,
     * except a web GET, which is redirected to the current path. Called once, after every route is defined.
     */
    public static function routes(): void
    {
        /** @var Router $router */
        $router = app('router');
        $current = $router->getRoutes()->getRoutes();
        foreach (['api', 'web'] as $group) {
            foreach ((array) config("passorde.aliases.routes.{$group}", []) as $old => $to) {
                $old = trim((string) $old, '/');
                foreach ($current as $route) {
                    if ($route->uri() !== trim((string) $to, '/')) {
                        continue;
                    }
                    foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                        if ($group === 'web' && $method === 'GET') {
                            Route::redirect($old, '/'.$route->uri());

                            continue;
                        }
                        $action = $route->getAction();
                        unset($action['as'], $action['prefix']);
                        $action['middleware'] = $route->middleware();
                        Route::match([$method], $old, $action);
                    }
                }
            }
        }
        $router->getRoutes()->refreshNameLookups();
    }

    /**
     * @return array<string, string>
     */
    private static function map(string $name): array
    {
        return array_filter((array) config("passorde.aliases.{$name}", []), fn ($old, $new) => is_string($new) && is_string($old) && $old !== '', ARRAY_FILTER_USE_BOTH);
    }
}
