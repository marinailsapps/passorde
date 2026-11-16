<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(fn () => $this->user = User::factory()->create(['password' => 'old-password-1']));

it('shows the account page', function () {
    actingAs($this->user)->get('/account')->assertOk();
});

it('changes the password', function () {
    actingAs($this->user)->put('/account/password', [
        'current_password' => 'old-password-1',
        'password' => 'new-password-22',
        'password_confirmation' => 'new-password-22',
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    expect(Hash::check('new-password-22', $this->user->fresh()->password))->toBeTrue();
});

it('requires the current password', function () {
    actingAs($this->user)->put('/account/password', [
        'current_password' => 'wrong',
        'password' => 'new-password-22',
        'password_confirmation' => 'new-password-22',
    ])->assertSessionHasErrors('current_password');
});

it('requires at least 12 characters', function () {
    actingAs($this->user)->put('/account/password', [
        'current_password' => 'old-password-1',
        'password' => 'eleven-char',
        'password_confirmation' => 'eleven-char',
    ])->assertSessionHasErrors('password');
});

it('refuses a password known from data breaches', function () {
    // HIBP range answer containing the SHA-1 suffix of the new password.
    $this->pwnedRange = substr(strtoupper(sha1('password1234')), 5).':42';

    actingAs($this->user)->put('/account/password', [
        'current_password' => 'old-password-1',
        'password' => 'password1234',
        'password_confirmation' => 'password1234',
    ])->assertSessionHasErrors(['password' => 'Ce mot de passe est apparu dans une fuite de données. Choisissez-en un autre.']);
});

it('signs every other device out when the password changes, and keeps this one', function () {
    config()->set('session.driver', 'database');
    $this->user->forceFill(['remember_token' => 'old-token'])->save();
    DB::table('sessions')->insert(['id' => 'stolen-phone', 'user_id' => $this->user->id, 'payload' => '', 'last_activity' => time()]);

    $response = actingAs($this->user)->put('/account/password', [
        'current_password' => 'old-password-1',
        'password' => 'new-password-22',
        'password_confirmation' => 'new-password-22',
    ])->assertSessionHasNoErrors();

    $token = $this->user->fresh()->remember_token;
    $remember = collect($response->headers->getCookies())->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));
    expect(DB::table('sessions')->where('id', 'stolen-phone')->exists())->toBeFalse()
        ->and($token)->not->toBe('old-token')
        ->and($remember)->not->toBeNull();
    get('/account')->assertOk();
});
