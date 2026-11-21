<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\BreachCheck;
use Illuminate\Console\Command;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Recovery from the server when the only admin is locked out: sets a new
 * password (asked twice, never on the command line), reactivates the
 * account and signs it out everywhere. Without network access the breach
 * check is skipped (App\Support\BreachCheck) and the password is set; the
 * command says so.
 */
class UserPasswordCommand extends Command
{
    protected $signature = 'user:password {identifier : Login or email address}';

    protected $description = 'Set a new password for a user and reactivate the account (admin lockout recovery)';

    public function handle(UncompromisedVerifier $verifier): int
    {
        $user = User::findByIdentifier((string) $this->argument('identifier'));

        if ($user === null) {
            $this->error('No user with this login or email.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('New password (12+ characters)');

        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => (string) $this->secret('Repeat it')],
            ['password' => ['required', 'confirmed', Password::defaults()]],
        );

        if ($validator->fails()) {
            $this->error($validator->errors()->first('password'));

            return self::FAILURE;
        }

        $user->password = $password;
        $user->is_active = true;
        $user->endSessions();
        $user->save();

        $this->info("Password set for {$user->login}; the account is active and signed out everywhere.");

        // The validator's own instance (a singleton): it remembers whether the last lookup reached the service.
        if ($verifier instanceof BreachCheck && $verifier->skipped()) {
            $this->warn('Have I Been Pwned could not be reached, so the password was set without the breach check (the log says why).');
        }

        return self::SUCCESS;
    }
}
