<?php

use App\Enums\AttributeSource;
use App\Models\Article;
use App\Models\ArticleAttribute;
use App\Models\User;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/*
| the production profile rule cannot take a site down
| during a deploy. deploy/deploy.sh checks, before it touches the running
| containers, that .env states PASSORDE_PROFILE and that the new image accepts the
| profile; the healthchecks do not depend on it (the digest and the settings page
| report a profile problem). The scripts run here with a fake `docker` on PATH.
*/

/**
 * Runs deploy.sh (or rollback.sh) in a scratch directory with this .env and a fake docker (exit code per subcommand).
 *
 * @param  array<string, int>  $exits  docker subcommand => exit code (default 0)
 * @param  array<string, string>  $extraEnv
 * @param  list<string>  $args
 * @return array{0: int, 1: string, 2: list<string>, 3: string} exit code, output, docker calls, .env afterwards
 */
function runDeploy(string $env, array $exits = [], array $extraEnv = [], string $script = 'deploy.sh', array $args = ['abc1234'], ?string $releases = null): array
{
    $dir = sys_get_temp_dir().'/deploy-'.Str::random(8);
    mkdir("{$dir}/deploy", 0777, true);
    mkdir("{$dir}/bin");
    foreach (['deploy.sh', 'rollback.sh'] as $file) {
        copy(base_path("deploy/{$file}"), "{$dir}/deploy/{$file}");
        chmod("{$dir}/deploy/{$file}", 0755);
    }
    file_put_contents("{$dir}/.env", $env);
    if ($releases !== null) {
        file_put_contents("{$dir}/releases.log", $releases);
    }
    touch("{$dir}/app-running");
    fakeDocker($dir, $exits);

    $result = Process::path($dir)->env(['PATH' => "{$dir}/bin:".getenv('PATH'), 'DEPLOY_DIR' => $dir, ...$extraEnv])->run(['sh', "{$dir}/deploy/{$script}", ...$args]);
    $calls = is_file("{$dir}/calls") ? array_values(array_filter(explode("\n", (string) file_get_contents("{$dir}/calls")))) : [];

    return [$result->exitCode() ?? -1, $result->output().$result->errorOutput(), $calls, (string) file_get_contents("{$dir}/.env")];
}

/**
 * A fake `docker` in {$dir}/bin: logs every call (and the PASSORDE_TAG it ran with, in {$dir}/tags); answers
 * `artisan list --raw` with the profile command unless {$dir}/{tag}-no-check exists, the migrations probe and table
 * from {$dir}/ran (no file: no table) and an image's migrations from {$dir}/shipped. $exits: the first pattern
 * found in the call (" pattern ") gives the exit code, else 0; "tag:<tag>" alone matches any call made with that
 * PASSORDE_TAG, "tag:<tag> <pattern>" both, "again:<pattern>" only from the second matching call on. $signals: pattern => "<SIGNAL> parent|grandparent": on the first such
 * call, that signal goes to the script that called docker (parent) or to its caller too (grandparent, e.g.
 * rollback.sh above deploy.sh, as a Ctrl-C reaches both), and the call fails.
 *
 * @param  array<string, int>  $exits
 * @param  array<string, string>  $signals
 */
function fakeDocker(string $dir, array $exits = [], array $signals = []): void
{
    $cases = implode("\n", array_map(function (string $pattern, int $code) use ($dir) {
        if (str_starts_with($pattern, 'tag:')) {
            [$tag, $rest] = array_pad(explode(' ', substr($pattern, 4), 2), 2, '');

            return $rest === ''
                ? "[ \"\${PASSORDE_TAG:-}\" = \"{$tag}\" ] && exit {$code}"
                : "if [ \"\${PASSORDE_TAG:-}\" = \"{$tag}\" ]; then case \" \$* \" in *\" {$rest} \"*) exit {$code} ;; esac; fi";
        }

        if (str_starts_with($pattern, 'again:')) {
            // From the second matching call on (the first one passes).
            $rest = substr($pattern, 6);
            $count = '"'.$dir.'/count-'.md5($rest).'"';

            return "case \" \$* \" in *\" {$rest} \"*) if [ -e {$count} ]; then exit {$code}; fi; touch {$count} ;; esac";
        }

        return "case \" \$* \" in *\" {$pattern} \"*) exit {$code} ;; esac";
    }, array_keys($exits), $exits));
    $kills = implode("\n", array_map(function (string $pattern, string $how) use ($dir) {
        [$signal, $whom] = explode(' ', $how);
        $marker = "{$dir}/signalled-".md5($pattern);
        $grand = $whom === 'grandparent' ? 'kill -s '.$signal.' "$(cut -d" " -f4 /proc/$PPID/stat)" 2>/dev/null; ' : '';

        return "case \" \$* \" in *\" {$pattern} \"*) if [ ! -e \"{$marker}\" ]; then touch \"{$marker}\"; {$grand}kill -s {$signal} \$PPID; exit 1; fi ;; esac";
    }, array_keys($signals), $signals));
    file_put_contents("{$dir}/bin/docker", <<<SH
        #!/bin/sh
        echo "\$*" >> "{$dir}/calls"
        echo "\${PASSORDE_TAG:-} \$*" >> "{$dir}/tags"
        {$kills}
        {$cases}
        case " \$* " in
        \t*" list --raw "*) [ -e "{$dir}/\${PASSORDE_TAG:-}-no-check" ] || echo "passorde:check-profile   Check the installation profile" ;;
        \t*"to_regclass"*) [ -e "{$dir}/ran" ] && echo t || echo f ;;
        \t*" exec -T postgres sh "*) cat "{$dir}/ran" 2>/dev/null ;;
        \t*" ps -q --status running app "*) [ -e "{$dir}/app-running" ] && echo c0ffee ;;
        \t*" exec -T app ls "*) cat "{$dir}/running" 2>/dev/null ;;
        \t*" migrate:rollback --step="*) step=\${*##*--step=}; step=\${step%% *}; sed -i "1,\${step}d" "{$dir}/ran" ;;
        \t*" up -d --remove-orphans "*) [ -e "{$dir}/ran.newer" ] && grep -q "^PASSORDE_TAG=\$(cat "{$dir}/newer")\$" "{$dir}/.env" && cp "{$dir}/ran.newer" "{$dir}/ran" ;;
        \t*" --entrypoint ls "*) if [ -e "{$dir}/shipped.\${PASSORDE_TAG:-}" ]; then cat "{$dir}/shipped.\${PASSORDE_TAG:-}"; else cat "{$dir}/shipped" 2>/dev/null; fi ;;
        esac
        exit 0
        SH);
    chmod("{$dir}/bin/docker", 0755);
}

it('refuses to deploy, touching nothing, while .env does not state PASSORDE_PROFILE', function (string $line) {
    [$code, $output, $calls, $env] = runDeploy("APP_ENV=production\nPASSORDE_TAG=old1234\n{$line}");

    expect($code)->toBe(1)
        ->and($output)->toContain('.env does not state PASSORDE_PROFILE')->toContain('PASSORDE_PROFILE=/var/www/html/profile')
        ->and($calls)->toBe([])
        ->and($env)->toContain('PASSORDE_TAG=old1234');
})->with(['missing' => [''], 'empty' => ["PASSORDE_PROFILE=\n"]]);

it('stops before switching when the new image refuses the profile; the old tag keeps running', function () {
    [$code, $output, $calls, $env] = runDeploy("APP_ENV=production\nPASSORDE_TAG=old1234\nPASSORDE_PROFILE=/var/www/html/profile\n", ['passorde:check-profile' => 1]);

    expect($code)->toBe(1)
        ->and($output)->toContain('does not pass passorde:check-profile with abc1234')->toContain('nothing was changed')
        ->and($calls[0])->toContain('compose pull')
        ->and(end($calls))->toBe('compose run --rm --no-deps -T -e AUTORUN_ENABLED=false app php artisan passorde:check-profile')
        ->and(callIndex($calls, 'artisan --version'))->toBeLessThan(count($calls) - 1)
        ->and(callIndex($calls, ' up '))->toBeFalse()
        ->and($env)->toContain('PASSORDE_TAG=old1234');
});

it('checks the profile with the new image, then deploys', function () {
    [$code, , $calls, $env] = runDeploy("APP_ENV=production\nPASSORDE_TAG=old1234\nPASSORDE_PROFILE=none\n");

    expect($code)->toBe(0)
        ->and(callIndex($calls, 'passorde:check-profile'))->toBeInt()
        ->and(collect($calls)->contains(fn (string $call) => str_contains($call, 'compose up -d --remove-orphans --wait')))->toBeTrue()
        ->and($env)->toContain('PASSORDE_TAG=abc1234');
});

it('keeps the healthchecks independent of the profile and backs the profile up', function () {
    $compose = (string) file_get_contents(base_path('compose.prod.yml'));

    expect($compose)->toContain('test: ["CMD", "php", "/var/www/html/artisan", "ops:check", "queue"]')
        ->not->toContain('"ops:check", "queue", "profile"')
        ->toContain('${PASSORDE_PROFILE_DIR:-./profile}:/backup/profile:ro')
        ->and((string) file_get_contents(base_path('deploy/backup.sh')))->toContain('cp -R "$profile_dir" "$workdir/profile"')
        ->and((string) file_get_contents(base_path('deploy/restore.sh')))->toContain('"/restore/$id/profile"');
});

it('takes the subnet of the edge network from EDGE_SUBNET, which TRUSTED_PROXIES trusts by default', function () {
    $compose = (string) file_get_contents(base_path('compose.prod.yml'));
    $example = (string) file_get_contents(base_path('.env.production.example'));

    expect($compose)->toContain('- subnet: ${EDGE_SUBNET:-172.30.93.0/24}')
        ->and($example)->toContain("\nEDGE_SUBNET=172.30.93.0/24\n")->toContain("\nTRUSTED_PROXIES=172.30.93.0/24\n");
});

it('shows a profile that does not fit the data on the settings page', function () {
    ArticleAttribute::query()->create(['article_id' => Article::factory()->create()->id, 'category' => 'PLATANE', 'variant' => 'nu', 'needs_review' => false, 'source' => AttributeSource::Manual]);

    actingAs(User::factory()->admin()->create())->get('/admin/settings')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('profileErrors', ['1 row(s) of article_attributes.category hold "PLATANE", which catalog.categories does not list.']));
});

it('stops the old queue worker before the new app migrates', function () {
    [$code, , $calls] = runDeploy("APP_ENV=production\nPASSORDE_TAG=old1234\nPASSORDE_PROFILE=none\n");
    $index = fn (string $part) => collect($calls)->search(fn (string $call) => str_contains($call, $part));

    expect($code)->toBe(0)
        ->and($index('compose stop queue'))->toBeInt()
        ->and($index('compose stop queue'))->toBeLessThan($index('compose up -d --remove-orphans --wait'));
});

/**
 * The index of the first docker call that contains $part, or false.
 *
 * @param  list<string>  $calls
 */
function callIndex(array $calls, string $part): int|false
{
    return collect($calls)->search(fn (string $call) => str_contains($call, $part));
}

it('goes back to the tag deployed before, or to a given one, by deploying it', function () {
    $env = "APP_ENV=production\nPASSORDE_TAG=new5678\nPASSORDE_PROFILE=none\n";
    $releases = "2026-11-14T08:00:00Z old1234 (previous none)\n2026-11-15T08:00:00Z new5678 (previous old1234)\n";

    [$code, $output, , $after] = runDeploy($env, script: 'rollback.sh', args: [], releases: $releases);
    expect($code)->toBe(0)->and($output)->toContain('rollback: from new5678 to old1234')->and($after)->toContain('PASSORDE_TAG=old1234');

    [$code, , , $after] = runDeploy($env, script: 'rollback.sh', args: ['pin0001'], releases: $releases);
    expect($code)->toBe(0)->and($after)->toContain('PASSORDE_TAG=pin0001');

    expect(runDeploy($env, script: 'rollback.sh', args: ['-x'])[0])->toBe(64)
        ->and(runDeploy($env, script: 'rollback.sh', args: [])[0])->toBe(64);
});

it('goes on with the images already on the server when the pull fails, only with DEPLOY_PULL_OPTIONAL', function () {
    $env = "APP_ENV=production\nPASSORDE_TAG=old1234\nPASSORDE_PROFILE=none\n";

    [$code, $output, $calls, $after] = runDeploy($env, ['pull' => 1]);
    expect($code)->toBe(1)
        ->and($output)->toContain('nothing was changed')
        ->and($calls)->toHaveCount(1)
        ->and($after)->toContain('PASSORDE_TAG=old1234');

    [$code, $output, , $after] = runDeploy($env, ['pull' => 1], ['DEPLOY_PULL_OPTIONAL' => '1']);
    expect($code)->toBe(0)
        ->and($output)->toContain('pull failed: using the images of abc1234 already on the server')
        ->and($after)->toContain('PASSORDE_TAG=abc1234');

    // An image that is not on the server either: stopped before anything changes.
    [$code, $output, , $after] = runDeploy($env, ['pull' => 1, 'artisan --version' => 1], ['DEPLOY_PULL_OPTIONAL' => '1']);
    expect($code)->toBe(1)
        ->and($output)->toContain('does not start')->toContain('nothing was changed')
        ->and($after)->toContain('PASSORDE_TAG=old1234');

    // rollback.sh passes the switch on to deploy.sh.
    $releases = "2026-11-14T08:00:00Z prev0001 (previous none)\n2026-11-15T08:00:00Z old1234 (previous prev0001)\n";
    [$code, , , $after] = runDeploy($env, ['pull' => 1], ['DEPLOY_PULL_OPTIONAL' => '1'], 'rollback.sh', [], $releases);
    expect($code)->toBe(0)->and($after)->toContain('PASSORDE_TAG=prev0001');
});

it('keeps a tagged backup past the retention, and records the heartbeat like any run', function (array $args, string $tags, ?string $name) {
    $dir = sys_get_temp_dir().'/backup-'.Str::random(8);
    mkdir("{$dir}/bin", 0777, true);
    file_put_contents("{$dir}/env", "APP_ENV=production\n");
    file_put_contents("{$dir}/bin/restic", "#!/bin/sh\necho \"\$*\" >> \"{$dir}/restic\"\ncase \"\$1\" in backup) echo 'snapshot 1a2b3c4d saved' ;; esac\nexit 0\n");
    file_put_contents("{$dir}/bin/pg_dump", "#!/bin/sh\nwhile [ \$# -gt 0 ]; do [ \"\$1\" = -f ] && echo dump > \"\$2\" && echo \"\$2\" >> \"{$dir}/dumped\"; shift; done\n");
    file_put_contents("{$dir}/bin/psql", "#!/bin/sh\necho \"\$*\" >> \"{$dir}/psql\"\ncat >> \"{$dir}/psql\"\n");
    file_put_contents("{$dir}/bin/timeout", "#!/bin/sh\nshift 3\nexec \"\$@\"\n");
    foreach (['restic', 'pg_dump', 'psql', 'timeout'] as $bin) {
        chmod("{$dir}/bin/{$bin}", 0755);
    }

    $result = Process::env([
        'PATH' => "{$dir}/bin:".getenv('PATH'), 'BACKUP_WORKDIR' => "{$dir}/work", 'BACKUP_ENV_FILE' => "{$dir}/env", 'BACKUP_PROFILE_DIR' => "{$dir}/none",
        'BACKUP_S3_ENDPOINT' => 'http://s3.test', 'BACKUP_S3_BUCKET' => 'b', 'BACKUP_S3_KEY' => 'k', 'BACKUP_S3_SECRET' => 's', 'RESTIC_PASSWORD' => 'p', 'PGDATABASE' => 'shop',
        ...($name === null ? [] : ['BACKUP_NAME' => $name]),
    ])->run(['sh', base_path('deploy/backup.sh'), ...$args]);
    $restic = (string) file_get_contents("{$dir}/restic");
    $host = $name ?? 'passorde';

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toContain('snapshot 1a2b3c4d saved')
        ->and((string) file_get_contents("{$dir}/dumped"))->toBe("{$dir}/work/shop.dump\n")
        ->and($restic)->toContain("backup --host {$host} {$tags} {$dir}/work")
        ->toContain("forget --host {$host} --keep-daily 30 --keep-monthly 12 --keep-tag keep")
        // ops_heartbeats: the same statement, with the snapshot id.
        ->and((string) file_get_contents("{$dir}/psql"))->toContain('-q -v ON_ERROR_STOP=1 -v detail=1a2b3c4d')
        ->toContain('insert into ops_heartbeats (name, last_ok_at, meta, updated_at)')->toContain("values ('backup', now(), json_build_object('snapshot', :'detail'), now())");
})->with([
    'nightly' => [[], '--tag nightly', null],
    'kept' => [['--keep', 'pre-switch'], '--tag nightly --tag keep --tag pre-switch', null],
    'another BACKUP_NAME' => [[], '--tag nightly', 'shop-a'],
]);

it('refuses a kept tag it cannot pass to restic', function () {
    $result = Process::run(['sh', base_path('deploy/backup.sh'), '--keep', 'Pre Switch']);

    expect($result->exitCode())->toBe(64)->and($result->errorOutput())->toContain('usage: backup.sh [--keep');
});
