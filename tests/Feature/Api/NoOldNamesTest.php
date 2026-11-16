<?php

use App\Models\Article;
use App\Models\Client;
use App\Models\Paytype;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

// A new installation has no older names (its profile names no aliases): answers carry the current names only.

it('answers no older name and serves no old address without aliases', function () {
    expect(config('passorde.aliases.client'))->toBe([])->and(config('passorde.aliases.routes'))->toBe(['api' => [], 'web' => []]);
    actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $article = Article::factory()->create(['unit_price_ht' => 30, 'vat_rate' => 5.5]);
    $paytype = Paytype::create(['evoliz_paytype_id' => 2, 'label' => 'Virement', 'is_enabled' => true]);

    $order = putJson('/api/orders/'.Str::uuid(), orderPayload($client, [[$article, 2]], $paytype))->assertCreated();
    expect(array_keys((array) $order->json('order.totals')))->toBe(['quantity', 'ht', 'vat', 'ttc', 'vat_by_rate'])
        ->and(array_keys((array) $order->json('order.client')))->toContain('extra_field');
    expect(collect((array) getJson('/api/reports/stock')->json('tabs'))->pluck('legacy_key')->filter()->all())->toBe([]);
    getJson('/api/families')->assertNotFound();
    get('/admin/family-thresholds')->assertNotFound();
});
