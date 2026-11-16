<?php

use App\Domain\Catalog\ArticleImport;
use App\Domain\Orders\OrderService;
use App\Enums\OrderStatus;
use App\Integrations\Sync\Push\EvolizDrafts;
use App\Integrations\Sync\Push\OrderPush;
use App\Models\Article;
use App\Models\Client;
use App\Models\Order;
use App\Models\Paytype;
use App\Models\User;
use App\Support\Profile\Profile;
use Database\Seeders\PaytypeSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\travelTo;

/*
| what each BL sent to Evoliz carries comes
| from the profile's evoliz and free_lines sections, the usual VAT rates of the
| import from catalog.usual_vat, the payment types enabled at first from
| evoliz.enabled_paytypes. The pinned bodies (OrderPushTest, NewArticleTest…)
| run on tests/Fixtures/profiles/pinned, which keeps today's bytes.
*/

/** config('passorde') as this profile directory gives it (null: the neutral defaults). */
function useProfile(?string $name): void
{
    config()->set('passorde', Profile::load($name === null ? null : base_path("tests/Fixtures/profiles/{$name}")));
}

beforeEach(function () {
    travelTo('2026-10-01 08:00:00');
    $this->author = User::factory()->create(['name' => 'Ysolde Dupont']);
    $this->client = Client::factory()->create(['evoliz_client_id' => 7000160]);
    $this->article = Article::factory()->create(['evoliz_article_id' => 7000240, 'unit_price_ht' => 10, 'vat_rate' => 5.5, 'designation' => 'ARTICLE', 'designation_raw' => null]);
    $this->paytype = Paytype::create(['evoliz_paytype_id' => 2, 'label' => 'Virement', 'is_enabled' => true]);
});

function defaultsOrder(): Order
{
    $order = app(OrderService::class)->save(test()->author, (string) Str::uuid(), [
        'client_id' => test()->client->id,
        'lines' => [
            ['article_id' => test()->article->id, 'quantity' => 2, 'unit_price_ht' => 10, 'is_free' => false, 'position' => 0],
            ['article_id' => test()->article->id, 'quantity' => 1, 'unit_price_ht' => 0, 'is_free' => true, 'position' => 1],
        ],
        'paytype_id' => test()->paytype->id,
    ])['order'];
    $order->forceFill(['status' => OrderStatus::Approved, 'approved_at' => now()])->save();

    return $order->fresh();
}

it('sends today\'s term, indemnity, comment and free-line text with the pinned profile', function () {
    $body = EvolizDrafts::delivery(defaultsOrder(), 7000160)->toRequest();

    expect($body['term'])->toBe(['paytermid' => 5, 'paytypeid' => 2, 'recovery_indemnity' => true])
        ->and($body['comment'])->toStartWith('Commande ')->toEndWith(' saisie par Ysolde Dupont')
        ->and($body['items'][1]['designation'])->toBe('ARTICLE / plant offert');
});

it('takes them from another profile', function () {
    useProfile('fictional');
    $order = defaultsOrder();

    $body = EvolizDrafts::delivery($order, 7000160)->toRequest();

    expect($body['term'])->toBe(['paytermid' => 7, 'paytypeid' => 2, 'recovery_indemnity' => false])
        ->and($body['comment'])->toBe("Bon de commande {$order->number} (Ysolde Dupont)")
        ->and($body['items'][1]['designation'])->toBe('ARTICLE / plant offert');
});

it('uses the neutral free-line text and comment with no profile', function () {
    useProfile(null);
    config()->set('server.evoliz.paytermid', 37);

    $body = EvolizDrafts::delivery(defaultsOrder(), 7000160)->toRequest();

    expect($body['term'])->toBe(['paytermid' => 37, 'paytypeid' => 2, 'recovery_indemnity' => true])
        ->and($body['comment'])->toEndWith(' saisie par Ysolde Dupont')
        ->and($body['items'][1]['designation'])->toBe('ARTICLE / offert');
});

it('lets EVOLIZ_PAYTERM_ID win over the profile (mocks)', function () {
    config()->set('server.evoliz.paytermid', 37);

    expect(EvolizDrafts::delivery(defaultsOrder(), 7000160)->toRequest()['term']['paytermid'])->toBe(37);
});

it('fails an order before anything is sent when no payment term is set', function () {
    useProfile(null);
    scriptEvoliz([]);
    $order = defaultsOrder();

    app(OrderPush::class)->push($order->id);

    expect($order->fresh())->status->toBe(OrderStatus::Failed)
        ->evoliz_error->toBe("Aucun délai de paiement Evoliz n'est réglé (evoliz.payterm_id du profil d'installation) : rien n'a été envoyé à Evoliz.");
    Http::assertNothingSent();

});

it('warns about an unusual VAT rate only against the profile\'s list', function (?string $profile, int $warnings) {
    fileMode();
    if ($profile !== 'pinned') {
        useProfile($profile);
    }

    $report = app(ArticleImport::class)->run("reference;designation;prix_ht;tva\nALPHA;X;1;10\n", User::factory()->admin()->create(), false)->toArray();

    expect($report)->toMatchArray(['created' => 1, 'warnings' => $warnings]);
})->with([
    'pinned (5.5 and 20)' => ['pinned', 1],
    'no profile: no list, no warning' => [null, 0],
    'fictional (10 listed)' => ['fictional', 0],
]);

it('enables the profile\'s payment types when they are first seeded', function (?string $profile, array $enabled) {
    Paytype::query()->delete();
    useProfile($profile);

    (new PaytypeSeeder)->run();

    expect(Paytype::query()->where('is_enabled', true)->orderBy('evoliz_paytype_id')->pluck('evoliz_paytype_id')->all())->toBe($enabled);
})->with([
    'no profile' => [null, [2, 3, 4, 5, 8]],
    'fictional' => ['fictional', [2, 4]],
]);
