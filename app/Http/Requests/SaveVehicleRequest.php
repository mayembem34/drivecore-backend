<?php

namespace App\Http\Requests;

use App\Models\Vehicle;
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $rules = ['registration' => [$required, 'string', 'max:40'], 'make' => [$required, 'string', 'max:100'], 'model' => [$required, 'string', 'max:100'], 'year' => ['nullable', 'integer', 'between:1900,2100'], 'odometer' => ['sometimes', 'integer', 'min:0'], 'status' => ['sometimes', Rule::in(['available', 'reserved', 'rented', 'maintenance', 'damaged', 'accident', 'awaiting_inspection', 'awaiting_repair', 'sold', 'decommissioned'])]];

        foreach (['vin' => 32, 'current_location' => 120, 'insurance_policy_number' => 64, 'insurance_provider' => 120, 'financing_provider' => 120] as $field => $length) {
            $rules[$field] = ['nullable', 'string', 'max:'.$length];
        }
        foreach (['acquisition_date', 'financing_end_date', 'insurance_expires_at', 'roadworthy_expires_at', 'licence_expires_at'] as $field) {
            $rules[$field] = ['nullable', 'date'];
        }
        foreach (['acquisition_cost_minor', 'financing_monthly_minor', 'current_book_value_minor', 'residual_value_minor'] as $field) {
            $rules[$field] = ['nullable', 'integer', 'between:0,1000000000000'];
        }
        $rules['category'] = ['nullable', Rule::in(['sedan', 'suv', 'pickup_4x4', 'van_minibus', 'hatchback', 'luxury', 'commercial', 'other'])];
        $rules['fuel_type'] = ['nullable', Rule::in(['petrol', 'diesel', 'hybrid', 'electric', 'lpg'])];
        $rules['transmission'] = ['nullable', Rule::in(['automatic', 'manual', 'cvt'])];
        $rules['financing_type'] = ['nullable', Rule::in(['cash_purchase', 'loan', 'finance_lease', 'operating_lease'])];
        abort_if($this->hasAny(Vehicle::FINANCIAL_FIELDS) && ! $this->user()->can('invoices.manage'), 403, 'Finance permission is required to edit asset values.');
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
