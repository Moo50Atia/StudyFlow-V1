<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        $rules = [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'password' => $this->isMethod('POST')
                ? 'required|string|min:8'
                : 'nullable|string|min:8',
            'role' => 'required|in:admin,teacher,student',
        ];

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['expected_version'] = ['required', 'integer', 'min:0'];
            $rules['expected_role'] = ['required', 'string', 'in:admin,teacher,student'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'The user name is required.',
            'name.max' => 'The name must not exceed 255 characters.',
            'email.required' => 'The email address is required.',
            'email.email' => 'Please enter a valid email address.',
            'email.unique' => 'This email address is already taken.',
            'password.required' => 'The password is required.',
            'password.min' => 'The password must be at least 8 characters.',
            'role.required' => 'Please select a role.',
            'role.in' => 'The role must be admin, teacher, or student.',
        ];
    }
}
