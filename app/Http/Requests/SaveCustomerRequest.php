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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $rules = ['driver_licence_expires_at' => ['nullable', 'date'], 'contact_person_name' => ['nullable', 'string', 'max:120'], 'contact_person_phone' => ['nullable', 'string', 'max:40'], 'billing_address' => ['nullable', 'string', 'max:255'], 'id_number' => ['nullable', 'string', 'max:64'], 'driver_licence_number' => ['nullable', 'string', 'max:64'], 'company_registration' => ['nullable', 'string', 'max:64'], 'tin' => ['nullable', 'string', 'max:64'], 'name' => [$required, 'string', 'max:255'], 'type' => [$required, Rule::in(['individual', 'corporate'])], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:50']];
        if ($this->isMethod('POST')) {
            $rules['company_id'] = ['required', 'integer'];
            $rules['branch_id'] = ['required', 'integer'];
        }

        $credit = ['credit_limit_minor', 'payment_terms_days', 'is_on_credit_hold', 'credit_hold_reason'];
        abort_if($this->hasAny($credit) && ! $this->user()->can('invoices.manage'), 403);
        $rules += ['credit_limit_minor' => ['nullable', 'integer', 'between:0,1000000000000'], 'payment_terms_days' => ['sometimes', 'integer', 'between:0,3650'], 'is_on_credit_hold' => ['sometimes', 'boolean'], 'credit_hold_reason' => ['nullable', 'string', 'max:255']];

        return $rules;
    }
}
