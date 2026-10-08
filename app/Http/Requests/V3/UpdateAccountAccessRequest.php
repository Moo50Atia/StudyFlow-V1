<?php

namespace App\Http\Requests\V3;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAccountAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'string', 'in:admin,teacher,student'],
            'is_active' => ['required', 'boolean'],
            'expected_version' => ['required', 'integer', 'min:0'],
            'expected_role' => ['required', 'string', 'in:admin,teacher,student'],
            'reason_code' => ['required', 'string', 'in:grant_access,suspend_access,restore_access,change_role'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $allowed = ['role', 'is_active', 'expected_version', 'expected_role', 'reason_code', '_token', '_method'];
            $extra = array_diff(array_keys($this->all()), $allowed);
            if (! empty($extra)) {
                $validator->errors()->add('extra_fields', 'Unexpected fields submitted.');
            }

            if ($this->input('role') === 'student' && filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN)) {
                $validator->errors()->add('is_active', 'Active student role is not permitted.');
            }
        });
    }

    public function booleanActive(): bool
    {
        return filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN);
    }
}
