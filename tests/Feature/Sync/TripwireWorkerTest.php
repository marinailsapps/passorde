<?php

use App\Integrations\Sync\SyncFileModeException;
use Illuminate\Support\Facades\Http;
use Passorde\Api\Contracts\EvolizApi;

/*
| the transport is resolved once in a long-running worker; the
| file-mode tripwire must still hold after Evoliz stops, and lift when it runs again.
*/

beforeEach(function () {
    scriptEvoliz(['GET /paytypes' => evolizJson(['data' => [['paytypeid' => 1, 'label' => 'Virement']]])]);
});

it('trips after a lapse or a key removal on the instance resolved while Evoliz ran', function (Closure $stop) {
    $api = app(EvolizApi::class);
    expect($api->get('/paytypes')['data'][0]['label'])->toBe('Virement');
    $sent = count(Http::recorded());

    $stop();

    expect(app(EvolizApi::class))->toBe($api)
        ->and(fn () => $api->get('/paytypes'))->toThrow(SyncFileModeException::class)
        ->and(fn () => $api->send('POST', '/deliveries', ['json' => []]))->toThrow(SyncFileModeException::class)
        ->and(fn () => iterator_to_array($api->paginate('/clients')))->toThrow(SyncFileModeException::class)
        ->and(count(Http::recorded()))->toBe($sent);
})->with([
    'licence lapsed' => [fn () => licenceExpired()],
    'a key removed' => [fn () => config()->set('passorde-connect.evoliz.secret_key', null)],
    'switched off' => [fn () => config()->set('server.evoliz_enabled', false)],
]);

it('calls Evoliz again on the same instance once Evoliz runs again', function () {
    $api = app(EvolizApi::class);
    $secret = config('passorde-connect.evoliz.secret_key');
    config()->set('passorde-connect.evoliz.secret_key', null);
    expect(fn () => $api->get('/paytypes'))->toThrow(SyncFileModeException::class);

    config()->set('passorde-connect.evoliz.secret_key', $secret);

    expect($api->get('/paytypes')['data'][0]['label'])->toBe('Virement');
});
