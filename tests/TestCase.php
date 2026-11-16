<?php

namespace Tests;

use App\Models\Setting;
use App\Support\EvolizState;
use App\Support\Profile\Brand;
use App\Support\Profile\Profile;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\ParallelTesting;
use Passorde\Testing\TestLicence;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** Have I Been Pwned range answer ("SUFFIX:count" lines); empty means never seen. */
    public string $pwnedRange = '';

    /**
     * RefreshDatabase empties the database it runs on. Variables from an env
     * file (artisan test with the env option, a stray DB_DATABASE in the shell)
     * win over phpunit.xml, so check the target before anything is dropped.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        $database = DB::connection()->getDatabaseName();
        // artisan test --parallel gives each process its own copy, named by Laravel "<database>_test_<token>":
        // that exact name, for this process's token, is the only other one allowed.
        $token = ParallelTesting::token();
        $allowed = is_string($token) ? ['passorde_test', "passorde_test_test_{$token}"] : ['passorde_test'];

        if (! in_array($database, $allowed, true)) {
            throw new RuntimeException("Refusing to run the tests on database '{$database}': only passorde_test (or its parallel copies) may be emptied.");
        }

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        // Statics outlive the application between tests: the next one boots with nothing remembered.
        Setting::flush();
        EvolizState::flush();

        parent::setUp();

        // Tests must not depend on a Vite build or a running dev server.
        $this->withoutVite();

        // Never reach Evoliz or Cloudflare from a test; every call must be faked.
        Http::preventStrayRequests();

        // Evoliz is active in tests (EVOLIZ_ENABLED in phpunit.xml): the test account's keys on
        // https://evoliz.test, where nothing answers unless the test fakes it. fileMode() removes them.
        config()->set('passorde-connect.evoliz', [...config('passorde-connect.evoliz'), 'base_url' => 'https://evoliz.test', 'company_id' => 10001, 'public_key' => 'public', 'secret_key' => 'secret']);

        // No installation profile under test (PASSORDE_PROFILE=none in phpunit.xml): the fictional pinned one
        // (tests/Fixtures/profiles/pinned), whose values keep the pinned Evoliz bodies byte for byte. A real
        // profile under test keeps its own values, which must give the same bytes.
        if (config('passorde.profile.loaded') !== true) {
            config()->set('passorde', [...Profile::load(base_path('tests/Fixtures/profiles/pinned')), 'profile' => config('passorde.profile')]);
        }

        // The profile check compares the running profile with the stamp of the asset build: never this
        // checkout's public/build, which follows whatever profile it was last built with.
        Brand::$builtFile = sys_get_temp_dir().'/commandes-tests-'.getmypid().'/no-build/profile.json';

        // Passorde checks its licence before any Evoliz call: a signed lifetime one, in a
        // directory of this test process (never storage/, where the dev licence lives).
        config()->set('passorde-connect.licence.path', sys_get_temp_dir().'/commandes-tests-'.getmypid().'/licence.key');
        TestLicence::install();

        // Password::defaults() checks new passwords against Have I Been Pwned.
        Http::fake(['api.pwnedpasswords.com/*' => fn () => Http::response($this->pwnedRange)]);
    }

    /**
     * A new application, booted from the environment as it is now: for a setting read only while booting.
     */
    public function bootAgain(): void
    {
        $this->refreshApplication();
    }
}
