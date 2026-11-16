<?php

namespace App\Http\Requests\Api;

use App\Domain\Catalog\Catalog;
use App\Domain\Catalog\NewArticles;
use App\Http\Api\ApiAliases;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body of PUT /api/article-requests/{uuid} (docs/API.md).
 */
class UpsertArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * No "new article" button in this profile (catalog.new_article null): there is nothing to create.
     * Old field names still accepted (App\Http\Api\ApiAliases).
     */
    protected function prepareForValidation(): void
    {
        abort_unless(NewArticles::enabled(), 404);
        ApiAliases::accept($this, ApiAliases::article());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'category' => ['required', Rule::in(Catalog::categoryValues())],
            'variant' => ['required', Rule::in(Catalog::countingVariants())],
            'unit_price_ht' => ['required', 'numeric', 'min:0', 'max:99999', 'decimal:0,2'],
        ];
    }
}
