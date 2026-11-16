<?php

use App\Domain\Catalog\NewArticles;
use App\Enums\AttributeSource;
use App\Enums\ClientSource;
use App\Enums\SyncKind;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Integrations\Sync\Jobs\SyncJob;
use App\Integrations\Sync\Sinks\Adoption;
use App\Models\Article;
use App\Models\ArticleAttribute;
use App\Models\ArticleRequest;
use App\Models\Client;
use App\Models\ClientChange;
use App\Models\Order;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// the first sync after standalone use attaches Evoliz ids instead of duplicating rows.

/** @param array<string, list<string>> $pages */
function adoptionSync(SyncKind $kind, array $pages): SyncRun
{
    fakeEvoliz($pages);
    $run = SyncRun::queue($kind, SyncTrigger::Console);
    SyncJob::dispatchSync($run);

    return $run->fresh();
}

/** @param array<string, mixed> $attributes */
function unlinkedArticle(string $reference, array $attributes = []): Article
{
    return Article::factory()->create([...$attributes, 'evoliz_article_id' => null, 'reference' => $reference]);
}

/** @param array<string, mixed> $attributes */
function unlinkedClient(string $code, array $attributes = []): Client
{
    return Client::factory()->create([...$attributes, 'evoliz_client_id' => null, 'evoliz_code' => $code, 'source' => ClientSource::Import]);
}

const ADOPTION_ARTICLES = ['articles' => ['articles-page-1', 'articles-page-2']];
const ADOPTION_CLIENTS = ['clients' => ['clients-page-1', 'clients-page-2'], 'contacts-clients' => ['contacts-clients']];

it('attaches Evoliz ids to the articles imported before, by reference, without a second row', function () {
    $topaze = unlinkedArticle('TOPAZE POM', ['designation' => 'Typed by hand', 'stock_qty' => 99]);
    // Compared as the sync stores text: trimmed, spaces collapsed, case-insensitive.
    $above = unlinkedArticle(' astra  mt pom ');
    ArticleAttribute::query()->create(['article_id' => $topaze->id, 'category' => 'POMMIER', 'variant' => 'nu', 'needs_review' => false, 'source' => 'manual']);

    $run = adoptionSync(SyncKind::Articles, ADOPTION_ARTICLES);

    expect($run->status)->toBe(SyncStatus::Succeeded)
        ->and(Article::query()->count())->toBe(6)
        ->and(Article::query()->whereNull('evoliz_article_id')->count())->toBe(0);

    $topaze->refresh();
    expect($topaze->evoliz_article_id)->toBe(7000250)
        // Evoliz's values win, as on any sync; the app's own attributes stay.
        ->and($topaze->designation)->not->toBe('Typed by hand')
        ->and($topaze->stock_qty)->not->toBe('99.00')
        ->and(ArticleAttribute::query()->where('article_id', $topaze->id)->value('source'))->toBe(AttributeSource::Manual);

    expect($above->refresh()->evoliz_article_id)->toBe(7000240)
        ->and($above->reference)->toBe('ASTRA MT POM');
});

it('never adopts or changes an article that already has an Evoliz id', function () {
    $linked = Article::factory()->create(['evoliz_article_id' => 111, 'reference' => 'TOPAZE POM', 'designation' => 'Mine']);

    adoptionSync(SyncKind::Articles, ADOPTION_ARTICLES);

    expect($linked->refresh()->evoliz_article_id)->toBe(111)
        ->and($linked->designation)->toBe('Mine')
        ->and(Article::query()->where('evoliz_article_id', 7000250)->exists())->toBeTrue()
        ->and(Article::query()->count())->toBe(7);
});

it('adopts nothing when two unlinked articles share the reference', function () {
    $first = unlinkedArticle('ASTRA POM', ['designation' => 'One']);
    $second = unlinkedArticle('astra pom', ['designation' => 'Two']);

    adoptionSync(SyncKind::Articles, ADOPTION_ARTICLES);

    expect($first->refresh()->evoliz_article_id)->toBeNull()
        ->and($second->refresh()->evoliz_article_id)->toBeNull()
        ->and($first->designation)->toBe('One')
        ->and(Article::query()->where('evoliz_article_id', 7000230)->exists())->toBeTrue()
        ->and(Article::query()->count())->toBe(8);
});

it('adopts nothing for an empty key or for two Evoliz records with one key', function () {
    unlinkedArticle('');
    unlinkedArticle('TWIN');

    expect(Adoption::articles([1 => '', 2 => null]))->toBe([])
        ->and(Adoption::articles([3 => 'TWIN', 4 => 'twin ']))->toBe([])
        ->and(Adoption::articles([5 => 'twin'])[5]->reference)->toBe('TWIN');
});

it('attaches Evoliz ids to the clients imported before, by code, keeping what only the app has', function () {
    $client = unlinkedClient('ESSAICLAIRVAL', ['company_name' => 'TYPED NAME', 'extra_field' => 'K123']);
    $order = Order::factory()->create(['client_id' => $client->id]);
    // Typed in the app, no code yet: nothing to match on.
    $typed = Client::factory()->create(['evoliz_client_id' => null, 'evoliz_code' => null, 'source' => ClientSource::App, 'company_name' => 'PROTOHELE']);

    adoptionSync(SyncKind::Clients, ADOPTION_CLIENTS);

    $client->refresh();
    expect($client->evoliz_client_id)->toBe(7000270)
        ->and($client->source)->toBe(ClientSource::Import)
        ->and($client->extra_field)->toBe('K123')
        ->and($client->company_name)->not->toBe('TYPED NAME')
        ->and($order->refresh()->client_id)->toBe($client->id)
        ->and(Client::query()->where('evoliz_code', 'ESSAICLAIRVAL')->count())->toBe(1)
        // The fields Evoliz changed are in the client's history, not silently overwritten.
        ->and(ClientChange::query()->where('client_id', $client->id)->exists())->toBeTrue()
        ->and($typed->refresh()->evoliz_client_id)->toBeNull()
        ->and(Client::query()->whereNotNull('evoliz_client_id')->count())->toBe(5);
});

it('never adopts a client that already has an Evoliz id, nor two unlinked clients with one code', function () {
    $linked = Client::factory()->create(['evoliz_client_id' => 222, 'evoliz_code' => 'ESSAICLAIRVAL', 'company_name' => 'KEEP']);
    $one = unlinkedClient('PROTOHELE');
    $two = unlinkedClient('protohele ');

    adoptionSync(SyncKind::Clients, ADOPTION_CLIENTS);

    expect($linked->refresh()->evoliz_client_id)->toBe(222)
        ->and($linked->company_name)->toBe('KEEP')
        ->and($one->refresh()->evoliz_client_id)->toBeNull()
        ->and($two->refresh()->evoliz_client_id)->toBeNull()
        ->and(Client::query()->where('evoliz_client_id', 7000270)->exists())->toBeTrue();
});

it('changes nothing on a second sync once everything is linked', function () {
    unlinkedArticle('TOPAZE POM');
    adoptionSync(SyncKind::Articles, ADOPTION_ARTICLES);
    $before = Article::query()->orderBy('id')->get(['id', 'evoliz_article_id', 'updated_at'])->toArray();

    $run = adoptionSync(SyncKind::Articles, ADOPTION_ARTICLES);

    expect($run->rows_changed)->toBe(0)
        ->and(Article::query()->orderBy('id')->get(['id', 'evoliz_article_id', 'updated_at'])->toArray())->toBe($before);
});

it('lands a new variety created in Evoliz on the unlinked article with its reference', function () {
    $local = unlinkedArticle('ASTRA MT POM');
    $request = ArticleRequest::query()->create([
        'uuid' => (string) Str::uuid(),
        'user_id' => User::factory()->create()->id,
        'name' => 'ASTRA',
        'reference' => 'ASTRA MT POM',
        'designation' => 'ASTRA',
        'unit_price_ht' => 10,
        'category' => 'POMMIER',
        'variant' => 'motte',
        'status' => ArticleRequest::PENDING,
    ]);

    $article = NewArticles::link($request, evolizFixture('article-7000240'));

    expect($article->id)->toBe($local->id)
        ->and($article->evoliz_article_id)->toBe(7000240)
        ->and(Article::query()->count())->toBe(1);
});

/** Runs $meanwhile once the sync has read its adoption candidates, as a push running alongside would. */
function whileAdopting(Closure $meanwhile): void
{
    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($meanwhile) {
        static $done = false;
        if (! $done && $event->message === 'sync.adoption' && ($event->context['adopted'] ?? 0) > 0) {
            $done = true;
            $meanwhile();
        }
    });
}

it('leaves a client a push linked meanwhile to another Evoliz id, and gives the record its own row', function () {
    $client = unlinkedClient('ESSAICLAIRVAL', ['company_name' => 'TYPED NAME']);
    whileAdopting(fn () => DB::table('clients')->where('id', $client->id)->update(['evoliz_client_id' => 999]));

    adoptionSync(SyncKind::Clients, ADOPTION_CLIENTS);

    expect($client->refresh()->evoliz_client_id)->toBe(999)
        ->and(Client::query()->where('evoliz_client_id', 7000270)->whereKeyNot($client->id)->exists())->toBeTrue();
});

it('updates a client a push linked meanwhile to the same Evoliz id, without a second row', function () {
    $client = unlinkedClient('ESSAICLAIRVAL', ['company_name' => 'TYPED NAME']);
    whileAdopting(fn () => DB::table('clients')->where('id', $client->id)->update(['evoliz_client_id' => 7000270]));

    adoptionSync(SyncKind::Clients, ADOPTION_CLIENTS);

    expect($client->refresh()->evoliz_client_id)->toBe(7000270)
        ->and($client->company_name)->not->toBe('TYPED NAME')
        ->and(Client::query()->where('evoliz_client_id', 7000270)->count())->toBe(1);
});

it('leaves an article a push linked meanwhile to another Evoliz id', function () {
    $topaze = unlinkedArticle('TOPAZE POM', ['designation' => 'Typed by hand']);
    whileAdopting(fn () => DB::table('articles')->where('id', $topaze->id)->update(['evoliz_article_id' => 999]));

    adoptionSync(SyncKind::Articles, ADOPTION_ARTICLES);

    expect($topaze->refresh()->evoliz_article_id)->toBe(999)
        ->and(Article::query()->where('evoliz_article_id', 7000250)->whereKeyNot($topaze->id)->exists())->toBeTrue();
});
