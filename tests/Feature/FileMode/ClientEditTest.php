<?php

use App\Enums\ClientChangeSource;
use App\Enums\ClientChangeStatus;
use App\Models\Client;
use App\Models\ClientChange;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\patch;

beforeEach(function () {
    fileMode();
    Http::preventStrayRequests();
    Queue::fake();
    actingAs($this->admin = User::factory()->admin()->create());
    $this->client = Client::factory()->imported()->create([
        'evoliz_code' => 'C00001', 'company_name' => 'SARL A', 'contact_name' => 'Old', 'address_line' => '1 rue A', 'postcode' => '36100',
        'town' => 'AVILLE', 'phone' => '09 70 00 00 00', 'mobile' => '06 00 00 00 00', 'email' => 'a@example.test', 'extra_field' => 'FR1', 'is_active' => true,
    ]);
    $this->fields = $this->client->only(['evoliz_code', 'company_name', 'contact_name', 'address_line', 'postcode', 'town', 'phone', 'mobile', 'email', 'extra_field', 'is_active']);
});

it('saves every field and records the change', function () {
    patch("/admin/clients/{$this->client->id}", [
        'evoliz_code' => 'C00002', 'company_name' => 'SARL B', 'contact_name' => 'New', 'address_line' => '2 rue B', 'postcode' => '29200',
        'town' => 'BVILLE', 'phone' => '09 70 11 11 11', 'mobile' => '07 00 00 00 00', 'email' => 'B@Example.test', 'extra_field' => 'FR2', 'is_active' => false,
    ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

    $client = $this->client->refresh();
    expect($client->evoliz_code)->toBe('C00002')
        ->and($client->company_name)->toBe('SARL B')
        ->and($client->email)->toBe('b@example.test')
        ->and($client->is_active)->toBeFalse();

    $change = ClientChange::query()->sole();
    expect($change->source)->toBe(ClientChangeSource::Admin)
        ->and($change->status)->toBe(ClientChangeStatus::Applied)
        ->and($change->user_id)->toBe($this->admin->id)
        ->and(array_keys($change->diff))->toEqualCanonicalizing(['evoliz_code', 'company_name', 'contact_name', 'address_line', 'postcode', 'town', 'phone', 'mobile', 'email', 'extra_field', 'is_active'])
        ->and($change->diff['company_name'])->toBe(['from' => 'SARL A', 'to' => 'SARL B']);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('records nothing and keeps updated_at when nothing changes', function () {
    Carbon::setTestNow(now()->addHour());
    $before = $this->client->updated_at->timestamp;

    patch("/admin/clients/{$this->client->id}", $this->fields)->assertSessionHasNoErrors();

    expect(ClientChange::query()->count())->toBe(0)
        ->and($this->client->refresh()->updated_at->timestamp)->toBe($before);
    Carbon::setTestNow();
});

it('clears a field', function () {
    patch("/admin/clients/{$this->client->id}", [...$this->fields, 'contact_name' => '', 'mobile' => null])->assertSessionHasNoErrors();

    expect($this->client->refresh()->contact_name)->toBeNull()->and($this->client->mobile)->toBeNull();
    expect(ClientChange::query()->sole()->diff['contact_name'])->toBe(['from' => 'Old', 'to' => null]);
});

it('checks a changed value like a correction', function (array $bad, string $field) {
    patch("/admin/clients/{$this->client->id}", [...$this->fields, ...$bad])->assertSessionHasErrors($field);
    expect(ClientChange::query()->count())->toBe(0);
})->with([
    [['postcode' => '1500'], 'postcode'],
    [['phone' => '12'], 'phone'],
    [['mobile' => '09 70 00 00 00'], 'mobile'],
    [['email' => 'nope'], 'email'],
    [['company_name' => ''], 'company_name'],
]);

it('does not let an imported value out of format block saving something else', function () {
    $this->client->update(['postcode' => 'B-1000', 'mobile' => '09 70 00 00 00']);

    patch("/admin/clients/{$this->client->id}", [...$this->fields, 'postcode' => 'B-1000', 'mobile' => '09 70 00 00 00', 'town' => 'NEW'])->assertSessionHasNoErrors();

    expect($this->client->refresh()->town)->toBe('NEW');
});

it('refuses a code another client without Evoliz id holds, typed clients included', function () {
    Client::factory()->fromApp()->create(['evoliz_code' => 'Taken']);

    patch("/admin/clients/{$this->client->id}", [...$this->fields, 'evoliz_code' => ' taken '])->assertSessionHasErrors('evoliz_code');

    expect($this->client->refresh()->evoliz_code)->toBe('C00001');
});

it('lets an admin give a typed client a code', function () {
    $typed = Client::factory()->fromApp()->create(['evoliz_code' => null]);

    patch("/admin/clients/{$typed->id}", [
        ...$typed->only(['company_name', 'contact_name', 'address_line', 'postcode', 'town', 'phone', 'mobile', 'email', 'extra_field', 'is_active']),
        'evoliz_code' => 'C00009',
    ])->assertSessionHasNoErrors();

    expect($typed->refresh()->evoliz_code)->toBe('C00009');
});

it('refuses sellers', function () {
    actingAs(User::factory()->create());

    patch("/admin/clients/{$this->client->id}", [...$this->fields, 'company_name' => 'X'])->assertForbidden();
});

it('keeps only the N° adhérent editable in Evoliz mode', function () {
    evolizMode();

    patch("/admin/clients/{$this->client->id}", [...$this->fields, 'company_name' => 'HACK', 'extra_field' => 'FR9'])->assertSessionHasNoErrors();

    expect($this->client->refresh()->company_name)->toBe('SARL A')->and($this->client->extra_field)->toBe('FR9');
});
