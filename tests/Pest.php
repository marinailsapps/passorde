<?php

use App\Http\Api\ApiAliases;
use App\Integrations\Sync\SyncProvider;
use App\Models\Article;
use App\Models\Client;
use App\Models\Paytype;
use App\Support\EvolizState;
use App\Support\Profile\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Passorde\Api\Contracts\EvolizApi;
use Passorde\Api\EvolizClient;
use Passorde\Api\EvolizToken;
use Passorde\Api\RateLimitMonitor;
use Passorde\Licence\LicenceState;
use Passorde\Testing\TestLicence;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * This test runs in file mode: Evoliz switched off, no Evoliz keys (App\Support\EvolizState).
 */
function fileMode(): void
{
    config()->set('server.evoliz_enabled', false);
    config()->set('passorde-connect.evoliz', [...config('passorde-connect.evoliz'), 'company_id' => null, 'public_key' => null, 'secret_key' => null]);
    forgetEvolizClients();
}

/**
 * Back to the suite's default after fileMode(): Evoliz on, the test account's keys.
 */
function evolizMode(): void
{
    config()->set('server.evoliz_enabled', true);
    evolizTestKeys();
}

/**
 * Passorde's transport is a singleton built from config: one resolved earlier
 * must not keep other keys or another mode (the file-mode tripwire is decided
 * when EvolizApi is resolved).
 */
function forgetEvolizClients(): void
{
    foreach ([EvolizApi::class, EvolizClient::class, EvolizToken::class, RateLimitMonitor::class, SyncProvider::class] as $abstract) {
        app()->forgetInstance($abstract);
    }
}

/**
 * The test Evoliz account (fixtures' company 10001 on https://evoliz.test).
 */
function evolizTestKeys(): void
{
    config()->set('passorde-connect.evoliz', [
        ...config('passorde-connect.evoliz'),
        'base_url' => 'https://evoliz.test',
        'company_id' => 10001,
        'public_key' => 'public',
        'secret_key' => 'secret',
    ]);
    forgetEvolizClients();
}

/**
 * A licence signed with the test key, fields overridden (product, connectors…), installed as the current one.
 *
 * @param  array<string, mixed>  $overrides
 */
function licenceWith(array $overrides, LicenceState $state = LicenceState::Valid): void
{
    TestLicence::install($state, $overrides);
    EvolizState::flush();
}

/** The Passorde licence expired, still within its 14 days of grace (TestLicence's dates). */
function licenceGrace(): void
{
    TestLicence::install(LicenceState::Grace);
    EvolizState::flush();
}

/** The Passorde licence expired past its grace: Passorde calls nothing. */
function licenceExpired(): void
{
    TestLicence::install(LicenceState::Expired);
    EvolizState::flush();
}

/** The Passorde licence file does not verify. */
function licenceInvalid(): void
{
    TestLicence::install(LicenceState::Invalid);
    EvolizState::flush();
}

/** No Passorde licence installed: treated as invalid. */
function licenceMissing(): void
{
    TestLicence::missing();
    EvolizState::flush();
}

/**
 * Decoded Evoliz fixture from tests/Fixtures/evoliz.
 *
 * @return array<string, mixed>
 */
function evolizFixture(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__."/Fixtures/evoliz/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Fake the Evoliz API: login plus paginated list endpoints served from fixtures.
 * $pages maps a company-scoped path ("clients") to its fixture pages in order.
 *
 * @param  array<string, list<string>>  $pages
 */
function fakeEvoliz(array $pages, int $rateLimitRemaining = 90): void
{
    evolizTestKeys();

    Http::fake(function (Request $request) use ($pages, $rateLimitRemaining) {
        $url = parse_url($request->url());

        if (($url['path'] ?? '') === '/api/login') {
            return Http::response(['access_token' => 'test-token', 'expires_at' => now()->addMinutes(20)->toIso8601String()]);
        }

        $path = Str::after($url['path'] ?? '', '/api/v1/companies/10001/');
        parse_str($url['query'] ?? '', $query);
        $page = (int) ($query['page'] ?? 1);
        $fixture = $pages[$path][$page - 1] ?? null;

        return $fixture === null
            ? Http::response(['error' => 'Not Found'], 404)
            : Http::response(evolizFixture($fixture), 200, ['X-RateLimit-Limit' => '100', 'X-RateLimit-Remaining' => (string) $rateLimitRemaining]);
    });
}

/**
 * A valid PUT /api/orders/{uuid} body; lines are [article, quantity, is_free?].
 *
 * @param  Client|array<string, mixed>  $client  an existing client or a new client object
 * @param  list<array{0: Article, 1: int|float, 2?: bool}>  $lines
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function orderPayload(Client|array $client, array $lines, Paytype $paytype, array $overrides = []): array
{
    return [
        ...($client instanceof Client ? ['client_id' => $client->id] : ['client' => $client]),
        'lines' => array_map(fn (array $line, int $position) => [
            'article_id' => $line[0]->id,
            'quantity' => $line[1],
            'unit_price_ht' => ($line[2] ?? false) ? 0 : (float) $line[0]->unit_price_ht,
            'is_free' => $line[2] ?? false,
            'position' => $position,
        ], $lines, array_keys($lines)),
        'paytype_id' => $paytype->id,
        'comment' => null,
        ...$overrides,
    ];
}

/**
 * Scripted Evoliz API for push tests. Keys are "METHOD /path" relative to the
 * company (query string ignored, "{id}" matches a number); values are closures
 * returning a response, or Http::sequence() for successive calls. Login always
 * succeeds; any other unscripted call fails the test (stray requests are refused).
 *
 * @param  array<string, Closure|ResponseSequence>  $routes
 */
function scriptEvoliz(array $routes): void
{
    evolizTestKeys();

    Http::fake(function (Request $request) use ($routes) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if ($path === '/api/login') {
            return Http::response(['access_token' => 'test-token']);
        }

        $key = $request->method().' '.Str::after($path, '/api/v1/companies/10001');

        foreach ($routes as $route => $responder) {
            $pattern = '#^'.str_replace('\{id\}', '\d+', preg_quote($route, '#')).'$#';
            if (preg_match($pattern, $key) === 1) {
                return $responder($request);
            }
        }

        throw new RuntimeException("Unscripted Evoliz call: {$key}");
    });
}

/**
 * A JSON response with a healthy rate-limit header.
 *
 * @param  array<string, string>  $headers
 */
function evolizJson(mixed $body, int $status = 200, array $headers = []): Closure
{
    return fn () => Http::response($body, $status, ['X-RateLimit-Remaining' => '90', ...$headers]);
}

/**
 * Rows of a JSON list response, for keyBy/pluck in assertions.
 *
 * @param  TestResponse<Response>  $response
 * @return Collection<int, array<string, mixed>>
 */
function jsonRows(TestResponse $response, string $path = 'data'): Collection
{
    /** @var list<array<string, mixed>> $rows */
    $rows = (array) $response->json($path);

    return collect($rows);
}

/**
 * Passorde's request log (passorde_requests), oldest first, for one subject
 * (App\Integrations\Sync\Push\Subject) or all of it.
 *
 * @return Collection<int, stdClass>
 */
function passordeRequests(?string $subjectType = null, ?int $subjectId = null): Collection
{
    return DB::table('passorde_requests')
        ->when($subjectType !== null, fn ($query) => $query->where('subject_type', $subjectType))
        ->when($subjectId !== null, fn ($query) => $query->where('subject_id', $subjectId))
        ->orderBy('id')
        ->get();
}

/**
 * The words an installation's profile brings (units, category names, the optional client field, counted
 * variants), taken from the fictional pinned and fictional test profiles, without the
 * words the neutral defaults use themselves: a neutral installation must show none of them.
 *
 * @return list<string>
 */
function installationWords(): array
{
    $neutral = mb_strtolower(json_encode(Profile::load(null), JSON_UNESCAPED_UNICODE));
    $words = [];
    foreach (['pinned', 'fictional'] as $name) {
        $profile = require base_path("tests/Fixtures/profiles/{$name}/profile.php");
        $catalog = $profile['catalog'] ?? [];
        $categories = $catalog['categories'] ?? [];
        array_pop($categories); // the catch-all ("other") category
        $texts = [
            ...array_values($profile['units'] ?? []),
            ...array_values($catalog['category_name'] ?? []),
            (string) ($profile['client']['extra_field']['label'] ?? ''),
            ...array_column($categories, 'label'),
            ...array_column(array_filter($catalog['variants'] ?? [], fn (array $v) => $v['counts'] ?? false), 'name'),
        ];
        foreach ($texts as $text) {
            foreach (preg_split('/[^\p{L}]+/u', mb_strtolower((string) $text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                if (mb_strlen($word) >= 4 && ! preg_match('/\b'.preg_quote($word, '/').'\b/u', $neutral)) {
                    $words[$word] = true;
                }
            }
        }
    }

    return array_keys($words);
}

/** The first installation word in a text, or null. */
function installationWordIn(string $text): ?string
{
    static $pattern = null;
    $pattern ??= '/(?<![\p{L}])('.implode('|', array_map(fn (string $w) => preg_quote($w, '/'), installationWords())).')(?![\p{L}])/iu';
    preg_match($pattern, $text, $match);

    return $match[1] ?? null;
}

/**
 * This test runs as the fictional installation of tests/Fixtures/profiles/renamed: its older field, route, text and
 * table names (aliases) are in force, its old addresses registered.
 */
function renamedInstallation(): void
{
    config()->set('passorde.aliases', (require base_path('tests/Fixtures/profiles/renamed/profile.php'))['aliases']);
    ApiAliases::routes();
}
