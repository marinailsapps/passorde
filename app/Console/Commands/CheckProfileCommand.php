<?php

namespace App\Console\Commands;

use App\Support\Profile\Profile;
use App\Support\Profile\ProfileCheck;
use Illuminate\Console\Command;

/**
 * Tells the installing developer what is wrong in the installation profile
 * (config/passorde.php, docs/RUNBOOK.md "Installation profile"): unknown keys,
 * missing or invalid entries, and values stored in the database that the
 * profile does not list. Exit 1 on any error.
 */
class CheckProfileCommand extends Command
{
    protected $signature = 'passorde:check-profile
        {--path= : Profile directory, "none" for no profile (default: PASSORDE_PROFILE, else profile/ if it holds a profile.php)}
        {--no-database : Skip the comparison with the values stored in the database}';

    protected $description = 'Check the installation profile against config/passorde.php and the database';

    public function handle(): int
    {
        $path = $this->option('path');
        $named = is_string($path) && $path !== '';
        $directory = $named ? Profile::directory($path) : config('passorde.profile.directory');
        $directory = is_string($directory) ? $directory : null;
        // A directory named by --path or PASSORDE_PROFILE must hold a profile.php; only "none" or unset mean no profile.
        $required = $named ? Profile::required($path) : (bool) config('passorde.profile.required');

        $result = ProfileCheck::run($directory, ! $this->option('no-database'), $required);

        $this->line($result['loaded']
            ? "Profile: {$directory}/".Profile::FILE
            : 'No profile'.($directory !== null ? " in {$directory}" : '').': the neutral defaults of config/passorde.php apply.');

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }
        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        if ($result['errors'] !== []) {
            $this->error(sprintf('%d error(s), %d warning(s).', count($result['errors']), count($result['warnings'])));

            return self::FAILURE;
        }

        $this->info($result['warnings'] === [] ? 'The profile is fine.' : sprintf('No error, %d warning(s).', count($result['warnings'])));

        return self::SUCCESS;
    }
}
