<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Recovery from the server when the only admin is locked out: sets a new
 * password (asked twice, never on the command line), reactivates the
 * account and signs it out everywhere.
 */
class UserPasswordCommand extends Command
{
    protected $signature = 'user:password {identifier : Login or email address}';

    protected $description = 'Set a new password for a user and reactivate the account (admin lockout recovery)';

    public function handle(): int
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

        return self::SUCCESS;
    }
}
