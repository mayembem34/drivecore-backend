<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = ['name' => ['required', 'string', 'max:255'], 'type' => ['required', Rule::in(['individual', 'corporate'])], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:50']];
        if ($this->isMethod('POST')) {
            $rules['company_id'] = ['required', 'integer'];
            $rules['branch_id'] = ['required', 'integer'];
        }

        return $rules;
    }
}
