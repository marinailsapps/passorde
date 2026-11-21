<?php

use App\Models\User;
use App\Support\BreachCheck;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

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

it('sets the password without the breach check when Have I Been Pwned cannot be reached, and says so', function () {
    $admin = User::factory()->admin()->create(['login' => 'admin', 'password' => 'unchanged-pass-1']);

    expect(app(UncompromisedVerifier::class))->toBeInstanceOf(BreachCheck::class);
    // A server without a way out: the lookup fails to connect ("Could not resolve host").
    $offline = (new HttpFactory)->fake(['api.pwnedpasswords.com/*' => HttpFactory::failedConnection()]);
    app()->instance(UncompromisedVerifier::class, new BreachCheck($offline));
    $log = Log::spy();

    artisan('user:password admin')
        ->expectsQuestion('New password (12+ characters)', 'recovered-pass-42')
        ->expectsQuestion('Repeat it', 'recovered-pass-42')
        ->expectsOutputToContain('without the breach check')
        ->assertSuccessful();

    expect(Hash::check('recovered-pass-42', $admin->fresh()->password))->toBeTrue();
    $log->shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => str_contains($message, 'breach check skipped')
        && str_contains((string) $context['reason'], 'Could not resolve host'));
    $log->shouldNotHaveReceived('error');
});

it('says nothing about the breach check when Have I Been Pwned answered', function () {
    User::factory()->admin()->create(['login' => 'admin']);

    artisan('user:password admin')
        ->expectsQuestion('New password (12+ characters)', 'recovered-pass-42')
        ->expectsQuestion('Repeat it', 'recovered-pass-42')
        ->doesntExpectOutputToContain('breach check')
        ->assertSuccessful();
});
