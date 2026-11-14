<?php

namespace App\Actions;

use App\Models\Rental;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\CompanyAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TransitionRental
{
    public function create(User $user, int $reservationId): Rental
    {
        $visible = CompanyAccess::record($user, Reservation::class, $reservationId);

        return DB::transaction(function () use ($user, $visible): Rental {
            Vehicle::whereKey($visible->vehicle_id)->lockForUpdate()->firstOrFail();
            $reservation = Reservation::lockForUpdate()->findOrFail($visible->id);
            abort_unless($reservation->status === 'confirmed' && $reservation->ends_at->isFuture(), 409, 'Reservation is not eligible for rental.');
            abort_if(Rental::where('reservation_id', $reservation->id)->exists(), 409, 'Rental already exists.');
            $rental = Rental::create(['company_id' => $reservation->company_id, 'branch_id' => $reservation->branch_id, 'vehicle_id' => $reservation->vehicle_id, 'reservation_id' => $reservation->id, 'status' => 'draft']);
            RecordAudit::write($user, 'rental.created', $rental);

            return $rental;
        }, 3);
    }

    public function handle(User $user, int $rentalId, string $action, array $data): Rental
    {
        $visible = CompanyAccess::record($user, Rental::class, $rentalId);

        return DB::transaction(function () use ($user, $visible, $action, $data): Rental {
            $vehicle = Vehicle::whereKey($visible->vehicle_id)->lockForUpdate()->firstOrFail();
            $rental = Rental::lockForUpdate()->findOrFail($visible->id);
            $reservation = Reservation::lockForUpdate()->findOrFail($rental->reservation_id);
            if ($action === 'handover') {
                abort_unless($rental->status === 'draft' && $vehicle->status === 'available' && $reservation->status === 'confirmed' && $reservation->starts_at->lte(now()) && $reservation->ends_at->isFuture(), 409, 'Rental cannot be handed over at this time.');
                if ($data['odometer'] < $vehicle->odometer) {
                    throw ValidationException::withMessages(['odometer' => 'Odometer cannot decrease.']);
                }
                $rental->fill(['status' => 'active', 'handed_over_at' => now(), 'handover_odometer' => $data['odometer'], 'handover_notes' => $data['notes']]);
                $vehicle->update(['status' => 'rented', 'odometer' => $data['odometer']]);
            } elseif ($action === 'return') {
                abort_unless($rental->status === 'active', 409, 'Only active rentals can be returned.');
                if ($data['odometer'] < max($vehicle->odometer, $rental->handover_odometer)) {
                    throw ValidationException::withMessages(['odometer' => 'Odometer cannot decrease.']);
                }
                $rental->fill(['status' => 'returned', 'returned_at' => now(), 'return_odometer' => $data['odometer'], 'return_notes' => $data['notes']]);
                $vehicle->update(['status' => $data['vehicle_status'], 'odometer' => $data['odometer']]);
                $reservation->update(['status' => 'completed']);
            } else {
                abort(422, 'Unknown rental transition.');
            }
            $rental->save();
            RecordAudit::write($user, 'rental.'.$action, $rental);

            return $rental;
        }, 3);
    }
}
