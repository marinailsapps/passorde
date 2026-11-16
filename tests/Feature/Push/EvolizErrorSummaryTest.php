<?php

use App\Domain\Orders\EvolizErrorSummary;
use App\Enums\OrderStatus;
use App\Models\Article;
use App\Models\Order;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

it('explains the common push errors in plain French', function (string $raw, string $summary) {
    expect(EvolizErrorSummary::for($raw))->toBe($summary);
})->with([
    'postcode' => ['HTTP 400 — address.postcode : The postcode must be 5 characters.', 'Evoliz refuse le code postal du client (5 chiffres attendus).'],
    'address' => ['HTTP 400 — address.addr : The addr field is required.', 'Evoliz refuse l’adresse du client.'],
    'town' => ['HTTP 400 — address.town : The town may not be greater than 50 characters.', 'Evoliz refuse l’adresse du client.'],
    'phone' => ['HTTP 400 — phone : The phone format is invalid.', 'Evoliz refuse le téléphone du client.'],
    'mobile' => ['HTTP 422 — mobile : The mobile must be a valid phone number.', 'Evoliz refuse le téléphone du client.'],
    'required field' => ['HTTP 400 — vat_number : The vat number field is required.', 'Il manque une information du client demandée par Evoliz : n° de TVA.'],
    'two problems' => [
        'HTTP 400 — vat_number : The vat number field is required. ; address.postcode : The postcode must be 5 characters.',
        'Evoliz refuse le code postal du client (5 chiffres attendus). Il manque une information du client demandée par Evoliz : n° de TVA.',
    ],
    'no answer' => ['Evoliz ne répond pas (cURL error 28: Operation timed out after 30001 milliseconds).', 'Evoliz ne répond pas ou répond trop lentement. Réessayez dans quelques minutes.'],
    'no answer, short' => ['Evoliz ne répond pas.', 'Evoliz ne répond pas ou répond trop lentement. Réessayez dans quelques minutes.'],
    'server error' => ['HTTP 503 — Service Unavailable', 'Evoliz ne répond pas ou répond trop lentement. Réessayez dans quelques minutes.'],
    'rate limit' => ['HTTP 429 — Too Many Attempts.', 'Evoliz a reçu trop de demandes en peu de temps. Réessayez dans une minute.'],
]);

it('leaves other errors to the raw text', function (?string $raw) {
    expect(EvolizErrorSummary::for($raw))->toBeNull();
})->with([
    'unknown field' => ['HTTP 400 — items.0.articleid : The selected articleid is invalid.'],
    'test mode' => ['Envoi Evoliz désactivé (mode test)'],
    'empty' => [''],
    'none' => [null],
]);

it('gives the summary to admins only, next to the raw error', function () {
    $author = User::factory()->create();
    $order = Order::factory()->for($author)->withLines([[Article::factory()->create(), 1]])->create();
    $order->forceFill(['status' => OrderStatus::Failed, 'evoliz_error' => 'HTTP 400 — address.postcode : The postcode must be 5 characters.'])->save();

    actingAs(User::factory()->admin()->create());
    getJson("/api/orders/{$order->uuid}")
        ->assertJsonPath('order.evoliz_error_summary', 'Evoliz refuse le code postal du client (5 chiffres attendus).')
        ->assertJsonPath('order.evoliz_error', 'HTTP 400 — address.postcode : The postcode must be 5 characters.');

    actingAs($author);
    getJson("/api/orders/{$order->uuid}")->assertJsonPath('order.evoliz_error_summary', null);
});
