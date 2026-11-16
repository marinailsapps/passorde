<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'login' => mb_strtolower(trim((string) $this->input('login'))),
            'email' => $this->filled('email') ? mb_strtolower(trim((string) $this->input('email'))) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->route('user');
        $ignore = $user instanceof User ? $user->id : null;

        return [
            'name' => ['required', 'string', 'max:120'],
            'login' => ['required', 'string', 'min:2', 'max:32', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'login')->ignore($ignore)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ignore)],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', new Enum(Role::class)],
            'is_active' => ['required', 'boolean'],
            // Only on creation; existing users get a new password through the dedicated action.
            'password' => $ignore === null ? ['required', 'string', Password::defaults()] : ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $user = $this->route('user');

            // An admin cannot lock themselves out.
            if ($user instanceof User && $user->is($this->user())) {
                if (! $this->boolean('is_active')) {
                    $validator->errors()->add('is_active', __('You cannot deactivate your own account.'));
                }
                if ($this->input('role') !== Role::Admin->value) {
                    $validator->errors()->add('role', __('You cannot remove your own admin role.'));
                }
            }
        });
    }
}
