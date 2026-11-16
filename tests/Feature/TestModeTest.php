<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Passorde\Api\Contracts\EvolizApi;
use Passorde\Api\Exceptions\WritesDisabledException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

// Test mode: PASSORDE_WRITES_ENABLED=false (the default) refuses every Evoliz write.

it('is off by default in .env.example', function () {
    expect(file_get_contents(base_path('.env.example')))->toContain("\nPASSORDE_WRITES_ENABLED=false\n");
});

it('refuses every write before anything is sent, login included', function (string $method) {
    scriptEvoliz([]);
    config()->set('passorde-connect.writes_enabled', false);

    expect(fn () => app(EvolizApi::class)->send($method, '/deliveries', ['json' => ['clientid' => 1]]))
        ->toThrow(WritesDisabledException::class, strtoupper($method).' /deliveries');

    Http::assertNothingSent();
})->with(['POST', 'PUT', 'PATCH', 'DELETE', 'post']);

it('still reads', function () {
    scriptEvoliz(['GET /paytypes' => evolizJson(['data' => [['paytypeid' => 1, 'label' => 'Virement']]])]);
    config()->set('passorde-connect.writes_enabled', false);

    expect(app(EvolizApi::class)->get('/paytypes')['data'][0]['label'])->toBe('Virement');
});

it('shares the flag with every page, so the layout shows the test-mode banner', function (bool $enabled) {
    config()->set('passorde-connect.writes_enabled', $enabled);

    actingAs(User::factory()->create());
    get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('evolizWritesEnabled', $enabled));
})->with(['writes enabled' => [true], 'test mode' => [false]]);
