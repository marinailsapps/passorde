<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\artisan;

it('sets a new password, reactivates the user and rotates remember-me', function () {
    $admin = User::factory()->admin()->create(['login' => 'admin', 'is_active' => false, 'remember_token' => 'old-token']);

    artisan('user:password admin')
        ->expectsQuestion('New password (12+ characters)', 'recovered-pass-42')
        ->expectsQuestion('Repeat it', 'recovered-pass-42')
        ->assertSuccessful();

    $admin->refresh();
    expect(Hash::check('recovered-pass-42', $admin->password))->toBeTrue()
        ->and($admin->is_active)->toBeTrue()
        ->and($admin->remember_token)->not->toBe('old-token');
});

it('refuses a short or mismatched password', function (string $repeat, string $password) {
    $admin = User::factory()->admin()->create(['login' => 'admin', 'password' => 'unchanged-pass-1']);

    artisan('user:password admin')
        ->expectsQuestion('New password (12+ characters)', $password)
        ->expectsQuestion('Repeat it', $repeat)
        ->assertFailed();

    expect(Hash::check('unchanged-pass-1', $admin->fresh()->password))->toBeTrue();
})->with([
    'too short' => ['eleven-char', 'eleven-char'],
    'mismatch' => ['recovered-pass-43', 'recovered-pass-42'],
]);

it('says so when the login is unknown', function () {
    artisan('user:password nobody')->assertFailed();
});
