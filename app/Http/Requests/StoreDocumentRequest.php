<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ($this->user()->can('documents.manage') || $this->user()->hasRole('driver'));
    }

    public function rules(): array
    {
        $rules = ['company_id' => ['required', 'integer'], 'branch_id' => ['required', 'integer'], 'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'], 'entity_type' => ['nullable', 'required_with:entity_id', Rule::in(['vehicle', 'customer', 'reservation', 'rental', 'quotation', 'invoice'])], 'entity_id' => ['nullable', 'required_with:entity_type', 'integer', 'min:1'], 'expires_at' => ['sometimes', 'nullable', 'date']];

        return $rules;
    }
}
