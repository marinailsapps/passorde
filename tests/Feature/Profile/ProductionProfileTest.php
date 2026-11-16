<?php

use App\Domain\Ops\HealthReport;
use App\Enums\AttributeSource;
use App\Models\Article;
use App\Models\ArticleAttribute;
use App\Support\Profile\Profile;

use function Pest\Laravel\artisan;

/*
| production never runs on the neutral defaults by
| accident. PASSORDE_PROFILE must be stated there (a directory, or "none"); the
| profile is checked against the database by `ops:check profile` (the queue
| container's healthcheck) and in the daily digest.
*/

it('refuses to boot in production without a stated PASSORDE_PROFILE', function (mixed $env) {
    expect(fn () => Profile::apply(['brand' => []], $env, 'production'))
        ->toThrow(RuntimeException::class, 'PASSORDE_PROFILE is not set: in production name the profile directory');
})->with(['unset' => [null], 'empty' => [''], 'blank' => ['  ']]);

it('boots in production on a stated profile or on "none", and elsewhere without one', function () {
    expect(Profile::apply(['brand' => []], 'none', 'production')['profile']['loaded'])->toBeFalse()
        ->and(Profile::apply(['brand' => []], base_path('tests/Fixtures/profiles/fictional'), 'production')['profile']['loaded'])->toBeTrue()
        ->and(Profile::apply(['brand' => []], '', 'local')['profile']['required'])->toBeFalse()
        ->and(Profile::apply(['brand' => []], null, 'testing')['profile']['required'])->toBeFalse();
});

it('fails ops:check profile and lists the errors in the digest while the profile does not fit the database', function () {
    artisan('ops:check profile')->assertSuccessful()->expectsOutputToContain('profile: ok');
    expect(collect(app(HealthReport::class)->sections())->pluck('title')->all())->not->toContain("Profil d'installation (1)");

    ArticleAttribute::query()->create(['article_id' => Article::factory()->create()->id, 'category' => 'PLATANE', 'variant' => 'nu', 'needs_review' => false, 'source' => AttributeSource::Manual]);

    $error = '1 row(s) of article_attributes.category hold "PLATANE", which catalog.categories does not list.';
    artisan('ops:check profile')->assertFailed()->expectsOutputToContain("profile: {$error}");
    expect(collect(app(HealthReport::class)->sections())->firstWhere('title', "Profil d'installation (1)")['lines'] ?? null)->toBe([$error]);
});
