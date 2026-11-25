<?php

namespace App\Actions;

use App\Models\Quotation;
use App\Models\Rental;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\CompanyAccess;
use App\Support\CreditControl;
use App\Support\OperationalAccess;
use App\Support\RatePricing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class TransitionRental
{
    public function create(User $user, int $reservationId, ?int $driverId = null, ?int $rateCardId = null): Rental
    {
        Gate::forUser($user)->authorize('rentals.manage');
        $visible = CompanyAccess::record($user, Reservation::class, $reservationId);

        return DB::transaction(function () use ($user, $visible, $driverId, $rateCardId): Rental {
            Vehicle::whereKey($visible->vehicle_id)->lockForUpdate()->firstOrFail();
            $reservation = Reservation::lockForUpdate()->findOrFail($visible->id);
            CompanyAccess::branch($user, $reservation->company_id, $reservation->branch_id);
            abort_unless($reservation->status === 'confirmed' && $reservation->ends_at->isFuture(), 409, 'Reservation is not eligible for rental.');
            abort_if(Rental::where('reservation_id', $reservation->id)->exists(), 409, 'Rental already exists.');
            OperationalAccess::validateDriver($driverId, $reservation);
            $quote = Quotation::whereHas('vehicles', fn ($q) => $q->where('quotation_vehicles.reservation_id', $reservation->id))->first();
            $cardId = $rateCardId ?? $quote?->rate_card_id;
            $card = $cardId ? RatePricing::card($user, $cardId, $reservation->company_id, $reservation->branch_id, $reservation->customer_id) : null;
            $rental = Rental::create(['rate_card_snapshot' => $card?->only($card->getFillable()), 'rate_card_id' => $cardId, 'driver_user_id' => $driverId, 'company_id' => $reservation->company_id, 'branch_id' => $reservation->branch_id, 'vehicle_id' => $reservation->vehicle_id, 'reservation_id' => $reservation->id, 'status' => 'draft']);
            RecordAudit::write($user, 'rental.created', $rental);
            if ($driverId !== null) {
                RecordAudit::write($user, 'rental.driver_assigned', $rental);
            }

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
            Gate::forUser($user)->authorize('update', $rental);
            if ($action === 'handover') {
                CreditControl::assertCanBook($reservation->customer_id);
                abort_unless($rental->status === 'draft' && $vehicle->status === 'available' && $reservation->status === 'confirmed', 409, 'Rental cannot be handed over at this time.');
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
                (new InvoiceReturnedRental)->handle($rental, $data, $user);
            } else {
                abort(422, 'Unknown rental transition.');
            }
            RecordInspection::handle($user, $rental, $action, $data);
            $rental->save();
            $scheduled = $action === 'handover' ? $reservation->starts_at : $reservation->ends_at;
            RecordAudit::write($user, 'rental.'.$action, $rental, metadata: ['scheduled_at' => $scheduled->toISOString(), 'delta_minutes' => (int) $scheduled->diffInMinutes(now(), false)]);

            return $rental;
        }, 3);
    }

    public function extend(User $user, int $rentalId, array $data): Rental
    {
        $visible = CompanyAccess::record($user, Rental::class, $rentalId);

        return DB::transaction(function () use ($user, $visible, $data): Rental {
            Vehicle::whereKey($visible->vehicle_id)->lockForUpdate()->firstOrFail();
            $rental = Rental::lockForUpdate()->findOrFail($visible->id);
            $reservation = Reservation::lockForUpdate()->findOrFail($rental->reservation_id);
            Gate::forUser($user)->authorize('rentals.manage');
            Gate::forUser($user)->authorize('update', $rental);
            abort_unless($rental->status === 'active', 409, 'Only active rentals can be extended.');
            $end = CarbonImmutable::parse($data['ends_at'])->utc();
            if (! $end->gt($reservation->ends_at)) {
                throw ValidationException::withMessages(['ends_at' => 'The new end must be later than the current end.']);
            }
            abort_if(Reservation::where('vehicle_id', $rental->vehicle_id)->whereKeyNot($reservation->id)->where('status', 'confirmed')->where('starts_at', '<', $end)->where('ends_at', '>', $reservation->ends_at)->exists(), 409, 'The extension overlaps another reservation.');
            $reservation->update(['ends_at' => $end]);
            RecordAudit::write($user, 'rental.extended', $rental, metadata: ['ends_at' => $end->toISOString(), 'notes' => $data['notes'] ?? null]);

            return $rental;
        }, 3);
    }

    public function assignDriver(User $user, int $rentalId, ?int $driverId): Rental
    {
        $visible = CompanyAccess::record($user, Rental::class, $rentalId);

        return DB::transaction(function () use ($user, $visible, $driverId): Rental {
            Vehicle::whereKey($visible->vehicle_id)->lockForUpdate()->firstOrFail();
            $rental = Rental::lockForUpdate()->findOrFail($visible->id);
            Gate::forUser($user)->authorize('rentals.manage');
            Gate::forUser($user)->authorize('update', $rental);
            abort_unless(in_array($rental->status, ['draft', 'active'], true), 409, 'Only draft or active rentals can be assigned.');
            OperationalAccess::validateDriver($driverId, $rental);
            $rental->update(['driver_user_id' => $driverId]);
            RecordAudit::write($user, 'rental.driver_assigned', $rental);

            return $rental;
        }, 3);
    }
}
