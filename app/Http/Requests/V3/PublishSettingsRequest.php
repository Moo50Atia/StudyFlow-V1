<?php

namespace App\Http\Requests\V3;

use App\Services\V3\Settings\SettingsSchema;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

class PublishSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            try {
                $raw = $this->except(['_token', '_method']);
                SettingsSchema::validate($raw);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        });
    }

    protected function failedValidation(Validator $validator)
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        // On browser validation failure: return errors but DO NOT flash submitted input
        $response = redirect()->back()->withErrors($validator);
        throw new HttpResponseException($response);
    }
}
