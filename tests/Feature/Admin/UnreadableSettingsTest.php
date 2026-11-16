<?php

use App\Domain\Ops\HealthReport;
use App\Integrations\Sync\EvolizSettings;
use App\Models\Setting;
use App\Models\User;
use App\Support\EvolizState;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

/*
| a saved value that no longer decrypts reads as unset
| and is reported; the digest names Evoliz keys that are missing.
*/

/** A settings row encrypted with another APP_KEY, as after a key rotation. */
function foreignSetting(string $key): void
{
    $other = new Encrypter(Encrypter::generateKey('aes-256-cbc'), 'aes-256-cbc');
    DB::table('settings')->insert(['key' => $key, 'value' => $other->encryptString('pk-from-another-key'), 'created_at' => now(), 'updated_at' => now()]);
    Setting::flush();
}

/**
 * @return array<string, list<string>>
 */
function digestSections(): array
{
    return collect(app(HealthReport::class)->sections())->mapWithKeys(fn (array $section) => [$section['title'] => $section['lines']])->all();
}

describe('S7: an undecryptable setting', function () {
    it('reads as unset and falls back to .env, without throwing', function () {
        $envKey = config('passorde-connect.evoliz.public_key');
        Setting::write('evoliz.company_id', '4242');
        foreignSetting('evoliz.public_key');

        EvolizSettings::apply();

        expect(Setting::read('evoliz.public_key'))->toBeNull()
            ->and(Setting::row('evoliz.public_key'))->toBeNull()
            ->and(Setting::read('evoliz.company_id'))->toBe('4242')
            ->and(Setting::unreadable())->toBe(['evoliz.public_key'])
            ->and(config('passorde-connect.evoliz.public_key'))->toBe($envKey)
            ->and(EvolizState::active())->toBeTrue();
    });

    it('keeps artisan working', function () {
        foreignSetting('evoliz.licence');

        artisan('migrate:status')->assertSuccessful();
        artisan('passorde:check-profile', ['--path' => 'none'])->assertSuccessful();
    });

    it('is named on the settings page and in the digest', function () {
        foreignSetting('evoliz.secret_key');

        actingAs(User::factory()->admin()->create());
        get('/admin/settings')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('unreadable', ['evoliz.secret_key']));

        expect(digestSections())->toHaveKey('Réglages illisibles (1)')
            ->and(digestSections()['Réglages illisibles (1)'][0])->toContain('evoliz.secret_key')->not->toContain('pk-from-another-key');
    });

    it('is named with the switch off too', function () {
        fileMode();
        foreignSetting('evoliz.public_key');

        actingAs(User::factory()->admin()->create());
        get('/admin/settings')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('evoliz', null)->where('unreadable', ['evoliz.public_key']));
    });

    it('reports nothing when every value reads', function () {
        Setting::write('evoliz.company_id', '4242');

        expect(Setting::unreadable())->toBe([])
            ->and(digestSections())->not->toHaveKey('Réglages illisibles (0)');
    });
});

describe('N7: Evoliz keys incomplete', function () {
    it('names the missing keys in the digest while Evoliz is switched on', function () {
        config()->set('passorde-connect.evoliz.secret_key', null);

        expect(digestSections())->toHaveKey('Clés Evoliz')
            ->and(digestSections()['Clés Evoliz'][0])->toContain('Clé secrète Evoliz')->not->toContain('Clé publique Evoliz');
    });

    it('says nothing with complete keys or with Evoliz switched off', function () {
        expect(digestSections())->not->toHaveKey('Clés Evoliz');

        fileMode();
        expect(digestSections())->not->toHaveKey('Clés Evoliz');
    });
});
