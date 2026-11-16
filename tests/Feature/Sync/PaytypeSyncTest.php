<?php

use App\Models\Paytype;
use Database\Seeders\PaytypeSeeder;

use function Pest\Laravel\artisan;
use function Pest\Laravel\seed;

it('keeps the admin choice and disables new paytypes', function () {
    seed(PaytypeSeeder::class);
    Paytype::query()->where('evoliz_paytype_id', 13)->delete();
    Paytype::query()->where('evoliz_paytype_id', 2)->update(['label' => 'Ancien libellé']);
    fakeEvoliz(['paytypes' => ['paytypes']]);

    artisan('evoliz:sync-paytypes')->assertSuccessful()->expectsOutputToContain('13 seen, 2 changed');

    expect(Paytype::query()->where('evoliz_paytype_id', 2)->first())
        ->label->toBe('Virement')
        ->is_enabled->toBeTrue()
        ->and(Paytype::query()->where('evoliz_paytype_id', 13)->value('is_enabled'))->toBeFalse();
});
