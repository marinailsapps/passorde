<?php

namespace App\Http\Requests\Api;

use App\Domain\Clients\ClientExtraField;
use App\Http\Api\ApiAliases;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of PUT /api/clients/{uuid} (docs/API.md): the same rules as `client` in
 * PUT /api/orders/{uuid}.
 */
class UpsertClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Old field names still accepted (App\Http\Api\ApiAliases).
     */
    protected function prepareForValidation(): void
    {
        ApiAliases::accept($this, ApiAliases::client());
    }

    /**
     * The optional client field is named by its label (profile client.extra_field).
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ClientExtraField::attributes(['']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:120'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'postcode' => ['required', 'string', 'regex:/^\d{5}$/'],
            'town' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'extra_field' => ['nullable', 'string', 'max:50'],
        ];
    }
}
