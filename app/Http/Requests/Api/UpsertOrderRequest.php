<?php

namespace App\Http\Requests\Api;

use App\Domain\Clients\ClientExtraField;
use App\Domain\Orders\OrderTotals;
use App\Http\Api\ApiAliases;
use App\Models\Article;
use App\Models\Client;
use App\Rules\FrenchPhone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Body of PUT /api/orders/{uuid} (docs/API.md).
 */
class UpsertOrderRequest extends FormRequest
{
    /** Order totals are decimal columns; keep far below their limits (9 999 999,99 € TTC). */
    public const MAX_TTC_CENTS = 999_999_999;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Old field names still accepted (App\Http\Api\ApiAliases): a phone's unsent outbox entry keeps its body.
     */
    protected function prepareForValidation(): void
    {
        ApiAliases::accept($this, ApiAliases::client(), 'client');
        ApiAliases::accept($this, ApiAliases::client(), 'client_changes');
    }

    /**
     * The optional client field is named by its label (profile client.extra_field).
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ClientExtraField::attributes(['client.', 'client_changes.']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['nullable', 'required_without:client', 'prohibits:client', 'integer'],
            'client' => ['nullable', 'required_without:client_id', 'array'],
            'client.uuid' => ['required_with:client', 'uuid'],
            'client.company_name' => ['required_with:client', 'string', 'max:120'],
            'client.contact_name' => ['nullable', 'string', 'max:120'],
            'client.address_line' => ['nullable', 'string', 'max:255'],
            'client.postcode' => ['required_with:client', 'string', 'regex:/^\d{5}$/'],
            'client.town' => ['required_with:client', 'string', 'max:120'],
            'client.phone' => ['nullable', 'string', 'max:30'],
            'client.email' => ['nullable', 'email', 'max:255'],
            'client.extra_field' => ['nullable', 'string', 'max:50'],

            // Corrections to an existing client's details, applied when the order is approved.
            'client_changes' => ['nullable', 'array', 'prohibits:client'],
            'client_changes.contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'client_changes.address_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            'client_changes.postcode' => ['sometimes', 'required', 'string', 'regex:/^\d{5}$/'],
            'client_changes.town' => ['sometimes', 'required', 'string', 'max:120'],
            'client_changes.phone' => ['sometimes', 'nullable', 'string', 'max:30', new FrenchPhone],
            'client_changes.mobile' => ['sometimes', 'nullable', 'string', 'max:30', new FrenchPhone(mobile: true)],
            'client_changes.email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'client_changes.extra_field' => ['sometimes', 'nullable', 'string', 'max:50'],

            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.article_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999', 'decimal:0,2'],
            'lines.*.unit_price_ht' => ['required', 'numeric', 'min:0', 'max:99999', 'decimal:0,2'],
            'lines.*.is_free' => ['sometimes', 'boolean'],
            'lines.*.position' => ['sometimes', 'integer', 'min:0'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],

            // Optional: usually unknown when the order is taken; the BL then has no paytype.
            'paytype_id' => ['nullable', 'integer', Rule::exists('paytypes', 'id')->where('is_enabled', true)],
            'comment' => ['nullable', 'string', 'max:2000'],
            'duplicated_from' => ['nullable', 'uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->filled('client_id') && ! Client::query()->whereKey($this->integer('client_id'))->where('is_active', true)->exists()) {
                $validator->errors()->add('client_id', __('This client is not available.'));
            }

            // Evoliz keeps a contact's name and a favourite contact's email: they can be replaced, not removed.
            if ($this->filled('client_id') && is_array($this->input('client_changes'))) {
                $client = Client::query()->find($this->integer('client_id'));
                foreach (['contact_name', 'email'] as $field) {
                    if ($client?->getAttribute($field) !== null
                        && array_key_exists($field, $this->input('client_changes'))
                        && trim((string) $this->input("client_changes.{$field}")) === '') {
                        $validator->errors()->add("client_changes.{$field}", __('This field can be replaced, not removed.'));
                    }
                }
            }

            /** @var list<array<string, mixed>> $lines */
            $lines = $this->input('lines', []);
            $articles = Article::query()->whereIn('id', array_column($lines, 'article_id'))->get()->keyBy('id');
            $active = $articles->where('is_active', true)->keys()->all();

            $paidArticles = [];
            foreach ($lines as $line) {
                if (! filter_var($line['is_free'] ?? false, FILTER_VALIDATE_BOOL)) {
                    $paidArticles[] = (int) $line['article_id'];
                }
            }

            foreach ($lines as $index => $line) {
                if (! in_array((int) $line['article_id'], $active, true)) {
                    $reference = $articles->get((int) $line['article_id'])?->reference;
                    $validator->errors()->add("lines.{$index}.article_id", $reference !== null
                        ? __('The article :reference is no longer in the catalogue: remove the line.', ['reference' => $reference])
                        : __('This article is no longer in the catalogue.'));
                } elseif (filter_var($line['is_free'] ?? false, FILTER_VALIDATE_BOOL) && ! in_array((int) $line['article_id'], $paidArticles, true)) {
                    $validator->errors()->add("lines.{$index}.is_free", __('A free line needs a paid line of the same article.'));
                }
            }

            if ($validator->errors()->isEmpty() && $this->ttcCents($lines, $articles) > self::MAX_TTC_CENTS) {
                $validator->errors()->add('lines', __('The order total exceeds :max €: check the quantities and prices.', ['max' => '9 999 999,99']));
            }
        });
    }

    /**
     * The order's TTC as OrderService will compute it (free lines at 0, VAT from the article).
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  Collection<int, Article>  $articles
     */
    private function ttcCents(array $lines, Collection $articles): int
    {
        return OrderTotals::compute(array_map(fn (array $line) => [
            'quantity' => is_numeric($line['quantity']) ? $line['quantity'] : 0,
            'unit_price_ht' => filter_var($line['is_free'] ?? false, FILTER_VALIDATE_BOOL) || ! is_numeric($line['unit_price_ht']) ? 0 : $line['unit_price_ht'],
            'vat_rate' => $articles->get((int) $line['article_id'])->vat_rate ?? 0,
        ], $lines))->ttcCents();
    }
}
