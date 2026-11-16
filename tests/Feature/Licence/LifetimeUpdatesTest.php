<?php

use App\Models\User;
use App\Support\EvolizState;
use App\Support\SyncMode;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/*
| A lifetime key carries a signed updates_until; a Passorde Connect release
| newer than that makes it invalid (updates_ended). A lifetime licence is the
| right to use the current major version without an end date, with updates for
| 12 months.
*/

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('runs standalone with a lifetime key whose updates ended, saying so to admins in the package\'s words', function () {
    licenceWith(['plan' => 'lifetime', 'expires_at' => null, 'updates_until' => '2020-01-01']);

    expect(SyncMode::isFile())->toBeTrue()
        ->and(EvolizState::reason())->toBe(EvolizState::REASON_LICENCE);

    $licence = null;
    actingAs($this->admin)->get('/')->assertOk()->assertInertia(function (AssertableInertia $page) use (&$licence) {
        $page->where('syncProvider', 'file');
        $licence = $page->toArray()['props']['syncLicence'];
    });

    expect($licence['state'])->toBe('invalid')
        // Passorde Connect's text, French dates and the way out in it, no renew line repeating it.
        ->and($licence['reason'])->toStartWith("Les mises à jour de cette licence couvrent les versions publiées jusqu'au 1er janvier 2020 inclus ;")
        ->and($licence['reason'])->toContain('revenez à une version publiée au plus tard le 1er janvier 2020')
        ->and(substr_count($licence['reason'], 'Prolongez'))->toBe(1)
        ->and($licence['renew'])->toBeNull();
    Http::assertNothingSent();
});

it('shows the end of the updates of a lifetime key beside holder, plan and number', function () {
    licenceWith(['plan' => 'lifetime', 'expires_at' => null, 'updates_until' => '2099-12-31']);

    actingAs($this->admin)->get('/admin/settings')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('evoliz.licence.state', 'valid')
        ->where('evoliz.licence.updates_until', '2099-12-31'));
});

it('shows no updates date for a subscription', function () {
    licenceWith(['plan' => '12m', 'expires_at' => '2099-06-30']);

    actingAs($this->admin)->get('/admin/settings')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('evoliz.licence.updates_until', null));
});
