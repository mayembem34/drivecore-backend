<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = ['company_id' => ['required', 'integer'], 'branch_id' => ['required', 'integer'], 'customer_id' => ['required', 'integer'], 'vehicle_id' => ['required', 'integer'], 'starts_at' => ['required', 'date', 'after_or_equal:now'], 'ends_at' => ['required', 'date', 'after:starts_at']];

        return $rules;
    }
}
