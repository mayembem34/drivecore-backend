<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('functions'))) {
            $this->merge(['functions' => array_map(fn ($item) => is_string($item) ? preg_replace('/\\s+/u', ' ', trim($item)) : $item, $this->input('functions'))]);
        }
        if (is_string($this->input('registration'))) {
            $this->merge(['registration' => mb_strtoupper($this->input('registration'))]);
        }
    }

    public function rules(): array
    {
        $rules = ['registration' => ['required', 'string', 'max:40'], 'make' => ['required', 'string', 'max:100'], 'model' => ['required', 'string', 'max:100'], 'year' => ['nullable', 'integer', 'between:1900,2100'], 'odometer' => ['sometimes', 'integer', 'min:0'], 'status' => ['sometimes', Rule::in(['available', 'maintenance', 'damaged'])]];
        $rules['daily_rate_minor'] = ['sometimes', 'integer', 'min:0', 'max:9007199254740991'];
        $rules['functions'] = ['sometimes', 'array', 'list', 'max:20'];
        $rules['functions.*'] = ['required', 'string', 'min:1', 'max:50', 'distinct:ignore_case'];
        $rules['odometer_unit'] = ['sometimes', Rule::in(['km'])];
        if ($this->isMethod('POST')) {
            $rules['company_id'] = ['required', 'integer'];
            $rules['branch_id'] = ['required', 'integer'];
        }

        return $rules;
    }
}
