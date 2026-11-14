<?php

namespace App\Actions;

use App\Models\Customer;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\CompanyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AllocateReservation
{
    public function handle(User $user, array $data): Reservation
    {
        $data['starts_at'] = CarbonImmutable::parse($data['starts_at'])->utc();
        $data['ends_at'] = CarbonImmutable::parse($data['ends_at'])->utc();

        return DB::transaction(function () use ($user, $data): Reservation {
            CompanyAccess::branch($user, $data['company_id'], $data['branch_id']);
            $vehicle = CompanyAccess::scope(Vehicle::query(), $user)->where('company_id', $data['company_id'])->where('branch_id', $data['branch_id'])->lockForUpdate()->findOrFail($data['vehicle_id']);
            CompanyAccess::scope(Customer::query(), $user)->where('company_id', $data['company_id'])->where('branch_id', $data['branch_id'])->findOrFail($data['customer_id']);
            if ($vehicle->status !== 'available') {
                throw ValidationException::withMessages(['vehicle_id' => 'Vehicle is not available.']);
            }
            $overlap = Reservation::where('vehicle_id', $vehicle->id)->where('status', 'confirmed')->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['vehicle_id' => 'Vehicle is already reserved for this period.']);
            }
            $reservation = Reservation::create([...$data, 'created_by' => $user->id, 'status' => 'confirmed']);
            RecordAudit::write($user, 'reservation.created', $reservation);

            return $reservation;
        }, 3);
    }
}
