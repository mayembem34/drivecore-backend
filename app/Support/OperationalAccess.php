<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Rental;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class OperationalAccess
{
    public static function rentals(User $user, string $ability = 'view'): Builder
    {
        $query = CompanyAccess::scope(Rental::query(), $user);
        if (! $user->can('rentals.'.$ability)) {
            abort_unless($user->hasRole('driver'), 403);
            $query->where('driver_user_id', $user->id);
        }

        return $query;
    }

    public static function documents(User $user, string $ability = 'view'): Builder
    {
        $query = CompanyAccess::scope(Document::query(), $user);
        if (! $user->can('documents.'.$ability)) {
            abort_unless($user->hasRole('driver'), 403);
            $query->where('entity_type', 'rental')->whereIn('entity_id', self::rentals($user)->select('rentals.id')->where('driver_user_id', $user->id));
        }

        return $query;
    }

    public static function documentTarget(User $user, array $data, bool $lock = false): void
    {
        $driverOnly = ! $user->can('documents.manage');
        abort_unless(! $driverOnly || $user->hasRole('driver'), 403);
        if (! isset($data['entity_type'])) {
            abort_if($driverOnly, 403);

            return;
        }
        $model = match ($data['entity_type']) {
            'vehicle' => Vehicle::class, 'customer' => Customer::class,
            'reservation' => Reservation::class, 'rental' => Rental::class, 'quotation' => Quotation::class, 'invoice' => Invoice::class,
            default => throw ValidationException::withMessages(['entity_type' => 'This entity type is not available yet.']),
        };
        abort_if($driverOnly && $model !== Rental::class, 403);
        $query = CompanyAccess::scope($model::query(), $user)->where('company_id', $data['company_id'])->where('branch_id', $data['branch_id']);
        if ($driverOnly) {
            $query->where('driver_user_id', $user->id);
        }
        $record = ($lock ? $query->lockForUpdate() : $query)->findOrFail($data['entity_id']);
        Gate::forUser($user)->authorize('view', $record);
    }

    public static function validateDriver(?int $driverId, Rental|Reservation $record): void
    {
        if ($driverId === null) {
            return;
        }
        $driver = User::whereKey($driverId)->lockForUpdate()->first();
        $membership = $driver?->companies()->where('companies.id', $record->company_id)->first();
        if (! $driver || ! $driver->is_active || ! $driver->hasRole('driver') || ! $membership || ($membership->pivot->branch_id !== null && (int) $membership->pivot->branch_id !== (int) $record->branch_id)) {
            throw ValidationException::withMessages(['driver_user_id' => 'Select an active driver in this company and branch.']);
        }
    }
}
