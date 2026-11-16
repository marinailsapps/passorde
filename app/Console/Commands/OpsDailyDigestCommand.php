<?php

namespace App\Console\Commands;

use App\Domain\Ops\HealthReport;
use App\Enums\Role;
use App\Mail\OpsDigestMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Scheduled at 07:30 Europe/Paris: emails the admins (and OPS_DIGEST_TO) the
 * points that need attention (HealthReport), and nothing when all is well.
 */
class OpsDailyDigestCommand extends Command
{
    protected $signature = 'ops:daily-digest {--print : Print the report instead of emailing it}';

    protected $description = 'Email the admins what needs attention (failed pushes, syncs, jobs, SMS, old pending orders, backups)';

    public function handle(HealthReport $report): int
    {
        $sections = $report->sections();

        if ($this->option('print')) {
            foreach ($sections as $section) {
                $this->line($section['title']);
                foreach ($section['lines'] as $line) {
                    $this->line("  - {$line}");
                }
            }
            $this->info($sections === [] ? 'Nothing to report.' : count($sections).' section(s).');

            return self::SUCCESS;
        }

        if ($sections === []) {
            $this->info('Nothing to report; no email sent.');

            return self::SUCCESS;
        }

        $recipients = User::query()
            ->where('role', Role::Admin)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->pluck('email')
            ->merge(config('server.ops.digest_to'))
            ->map(fn ($email) => mb_strtolower((string) $email))
            ->unique()
            ->values();

        if ($recipients->isEmpty()) {
            Log::warning('ops:daily-digest has something to report but no admin has an email address (set OPS_DIGEST_TO).');
            $this->warn('Nothing sent: no admin email and OPS_DIGEST_TO is empty.');

            return self::FAILURE;
        }

        Mail::to($recipients->all())->send(new OpsDigestMail($sections));
        $this->info('Digest sent to '.$recipients->count().' recipient(s).');

        return self::SUCCESS;
    }
}
