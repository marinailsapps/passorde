<?php

use App\Enums\Role;
use App\Models\Paytype;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

use function Pest\Laravel\seed;

beforeEach(fn () => config()->set('server.admin', [
    'login' => 'Chef',
    'email' => 'chef@example.test',
    'password' => 'first-admin-pass',
]));

it('creates the first admin from the environment', function () {
    seed(DatabaseSeeder::class);

    $admin = User::query()->sole();
    expect($admin->login)->toBe('chef')
        ->and($admin->role)->toBe(Role::Admin)
        ->and($admin->is_active)->toBeTrue();
});

it('does not create a second admin', function () {
    User::factory()->admin()->create();

    seed(DatabaseSeeder::class);

    expect(User::query()->count())->toBe(1);
});

it('seeds the 13 Evoliz paytypes with the default ones enabled', function () {
    seed(DatabaseSeeder::class);

    expect(Paytype::query()->count())->toBe(13)
        ->and(Paytype::query()->where('is_enabled', true)->orderBy('evoliz_paytype_id')->pluck('label')->all())
        ->toBe(['Virement', 'Carte bancaire', 'Chèque', 'Espèces', 'Prélèvement']);
});

it('keeps the admin choice when seeding again', function () {
    seed(DatabaseSeeder::class);
    Paytype::query()->where('evoliz_paytype_id', 2)->update(['is_enabled' => false]);

    seed(DatabaseSeeder::class);

    expect(Paytype::query()->where('evoliz_paytype_id', 2)->value('is_enabled'))->toBeFalse();
});
