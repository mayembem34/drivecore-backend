<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Document;
use App\Models\RateCard;
use App\Models\Rental;
use App\Models\User;
use App\Models\VehicleInspection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RecordInspection
{
    public static function handle(User $user, Rental $rental, string $type, array $data): void
    {
        $company = Company::findOrFail($rental->company_id);
        if (! isset($data['inspection'])) {
            if ($company->require_inspection_photos) {
                throw ValidationException::withMessages(['inspection' => 'An inspection with photographs is required.']);
            }

            return;
        }
        $d = $data['inspection'];
        $d['odometer'] = $data['odometer'];
        $rules = ['fuel_level_pct' => ['required', 'integer', 'between:0,100'], 'odometer' => ['required', 'integer', 'min:0'], 'damage_notes' => ['nullable', 'string', 'max:10000'], 'customer_acknowledged_by' => ['nullable', 'string', 'max:120'], 'customer_acknowledged_at' => ['nullable', 'date']];
        foreach (['exterior_ok', 'interior_ok', 'tyres_ok', 'spare_tyre_ok', 'tools_ok', 'accessories_ok', 'documents_ok'] as $check) {
            $rules[$check] = ['sometimes', 'boolean'];
        } if (isset($d['signature_data_url'])) {
            $value = $d['signature_data_url'];
            $raw = is_string($value) && strlen($value) <= 204822 && str_starts_with($value, 'data:image/png;base64,') ? base64_decode(substr($value, 22), true) : false;
            $image = $raw === false ? false : @getimagesizefromstring($raw);
            if ($raw === false || strlen($raw) > 153600 || ! $image || $image[2] !== IMAGETYPE_PNG) {
                throw ValidationException::withMessages(['signature_data_url' => 'Provide a valid PNG signature of at most 150 KB.']);
            }
            $rules['signature_data_url'] = ['string', 'max:204822'];
        }
        $fields = Validator::make($d, $rules)->validate();
        if ($company->require_inspection_photos && ! Document::where('company_id', $rental->company_id)->where('branch_id', $rental->branch_id)->where('entity_type', 'rental')->where('entity_id', $rental->id)->where('mime_type', 'like', 'image/%')->exists()) {
            throw ValidationException::withMessages(['inspection' => 'Upload at least one rental inspection photograph.']);
        }
        VehicleInspection::create($fields + ['company_id' => $rental->company_id, 'branch_id' => $rental->branch_id, 'rental_id' => $rental->id, 'vehicle_id' => $rental->vehicle_id, 'recorded_by' => $user->id, 'type' => $type]);
    }

    public static function difference(Rental $rental): ?array
    {
        $records = VehicleInspection::where('rental_id', $rental->id)->orderBy('id')->get();
        $out = $records->firstWhere('type', 'handover');
        $back = $records->firstWhere('type', 'return');
        if (! $out || ! $back) {
            return null;
        } $failed = [];
        foreach (['exterior_ok', 'interior_ok', 'tyres_ok', 'spare_tyre_ok', 'tools_ok', 'accessories_ok', 'documents_ok'] as $check) {
            if ($out->$check && ! $back->$check) {
                $failed[] = $check;
            }
        } $limit = RateCard::find($rental->rate_card_id)?->daily_km_limit;
        $days = max(1, (int) ceil($rental->reservation->starts_at->diffInSeconds($rental->reservation->ends_at) / 86400));

        return ['fuel_shortage_pct' => max(0, $out->fuel_level_pct - $back->fuel_level_pct), 'newly_failed_checks' => $failed, 'distance_km' => max(0, $back->odometer - $out->odometer), 'excess_km' => $limit === null ? 0 : max(0, $back->odometer - $out->odometer - $days * $limit)];
    }
}
