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
        if (is_string($this->input('registration'))) {
            $this->merge(['registration' => mb_strtoupper($this->input('registration'))]);
        }
    }

    public function rules(): array
    {
        $rules = ['registration' => ['required', 'string', 'max:40'], 'make' => ['required', 'string', 'max:100'], 'model' => ['required', 'string', 'max:100'], 'year' => ['nullable', 'integer', 'between:1900,2100'], 'odometer' => ['sometimes', 'integer', 'min:0'], 'status' => ['sometimes', Rule::in(['available', 'maintenance', 'damaged'])]];
        $rules['daily_rate_minor'] = ['sometimes', 'integer', 'min:0', 'max:9007199254740991'];
        $rules['functions'] = ['sometimes', 'array', 'list', 'max:7'];
        $rules['functions.*'] = ['required', 'string', 'distinct', Rule::in(['gps_tracker', 'automatic', 'awd_4x4', 'air_conditioning', 'chauffeur_eligible', 'bluetooth', 'child_seat'])];
        $rules['odometer_unit'] = ['sometimes', Rule::in(['km'])];
        if ($this->isMethod('POST')) {
            $rules['company_id'] = ['required', 'integer'];
            $rules['branch_id'] = ['required', 'integer'];
        }

        return $rules;
    }
}
