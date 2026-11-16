<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\travel;

beforeEach(fn () => Notification::fake());

it('emails a reset link to a user with an email address', function () {
    $user = User::factory()->create(['login' => 'ysolde', 'email' => 'ysolde@example.test']);

    post('/forgot-password', ['identifier' => 'ysolde'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Si un compte avec une adresse e-mail correspond, un lien de réinitialisation a été envoyé. Sinon, demandez un nouveau mot de passe à l\'administrateur.');

    Notification::assertSentTo($user, ResetPassword::class);
});

it('gives the same answer for an unknown, an email-less and a deactivated account', function () {
    User::factory()->withoutEmail()->create(['login' => 'paul']);
    User::factory()->create(['login' => 'ancien', 'is_active' => false]);

    foreach (['nobody', 'paul', 'ancien'] as $identifier) {
        post('/forgot-password', ['identifier' => $identifier])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Si un compte avec une adresse e-mail correspond, un lien de réinitialisation a été envoyé. Sinon, demandez un nouveau mot de passe à l\'administrateur.');
    }

    Notification::assertNothingSent();
});

it('resets the password with a valid token and signs every device out', function () {
    config()->set('session.driver', 'database');
    $user = User::factory()->create(['login' => 'ysolde', 'email' => 'ysolde@example.test', 'remember_token' => 'old-token']);
    DB::table('sessions')->insert(['id' => 'lost-phone', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    post('/forgot-password', ['identifier' => 'ysolde']);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        get("/reset-password/{$notification->token}?email={$user->email}")->assertOk();

        post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertRedirect('/login')->assertSessionHasNoErrors();

        return true;
    });

    expect(Hash::check('brand-new-password', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->remember_token)->not->toBe('old-token')
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();
});

it('rejects a reset token older than 60 minutes', function () {
    $user = User::factory()->create(['login' => 'ysolde', 'email' => 'ysolde@example.test']);
    post('/forgot-password', ['identifier' => 'ysolde']);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        travel(61)->minutes();

        post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasErrors(['password' => 'Ce lien de réinitialisation est invalide ou a expiré.']);

        return true;
    });
});

it('requires at least 12 characters for the new password', function () {
    post('/reset-password', [
        'token' => 'token',
        'email' => 'ysolde@example.test',
        'password' => 'eleven-char',
        'password_confirmation' => 'eleven-char',
    ])->assertSessionHasErrors('password');
});

it('limits reset requests to 5 per minute', function () {
    foreach (range(1, 5) as $attempt) {
        post('/forgot-password', ['identifier' => "user{$attempt}"])->assertSessionHasNoErrors();
    }

    post('/forgot-password', ['identifier' => 'another'])->assertSessionHasErrors('identifier');
});
