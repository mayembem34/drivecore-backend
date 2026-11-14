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

    public function rules(): array
    {
        $rules = ['registration' => ['required', 'string', 'max:40'], 'make' => ['required', 'string', 'max:100'], 'model' => ['required', 'string', 'max:100'], 'year' => ['nullable', 'integer', 'between:1900,2100'], 'odometer' => ['sometimes', 'integer', 'min:0'], 'status' => ['sometimes', Rule::in(['available', 'maintenance', 'damaged'])]];
        if ($this->isMethod('POST')) {
            $rules['company_id'] = ['required', 'integer'];
            $rules['branch_id'] = ['required', 'integer'];
        }

        return $rules;
    }
}
