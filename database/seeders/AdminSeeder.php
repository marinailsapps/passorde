<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the first admin from ADMIN_LOGIN / ADMIN_EMAIL / ADMIN_PASSWORD,
 * only when no admin exists yet. Safe to run on every deploy.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        if (User::query()->where('role', Role::Admin)->exists()) {
            $this->command->info('An admin already exists; skipping.');

            return;
        }

        $login = (string) config('server.admin.login');
        $password = (string) config('server.admin.password');

        if ($login === '' || mb_strlen($password) < 12) {
            $this->command->warn('ADMIN_LOGIN and ADMIN_PASSWORD (12+ characters) must be set to create the first admin.');

            return;
        }

        User::create([
            'name' => 'Administrateur',
            'login' => $login,
            'email' => config('server.admin.email') ?: null,
            'password' => $password,
            'role' => Role::Admin,
            'is_active' => true,
        ]);

        $this->command->info("Admin \"{$login}\" created.");
    }
}
