<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\TransitionRental;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\DirectoryQuery;
use App\Support\OperationalAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RentalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return RecordResource::collection(DirectoryQuery::apply($request, OperationalAccess::rentals($request->user()), ['vehicle:registration', 'reservation.customer:name', 'handover_notes', 'return_notes'], ['status' => ['draft', 'active', 'returned']], integerFilters: ['driver_user_id', 'vehicle_id'], sortColumns: ['id' => 'id', 'status' => 'status', 'vehicle_registration' => Vehicle::select('registration')->whereColumn('vehicles.id', 'rentals.vehicle_id')->limit(1), 'customer_name' => Customer::select('customers.name')->join('reservations', 'reservations.customer_id', '=', 'customers.id')->whereColumn('reservations.id', 'rentals.reservation_id')->limit(1), 'driver_name' => User::select('name')->whereColumn('users.id', 'rentals.driver_user_id')->limit(1)])->with(['vehicle', 'reservation.customer', 'driver', 'invoice'])->orderBy('id')->paginate(25)->withQueryString());
    }

    public function show(Request $request, int $rental): RecordResource
    {
        $record = OperationalAccess::rentals($request->user())->with(['vehicle', 'reservation.customer', 'driver', 'invoice'])->findOrFail($rental);
        Gate::authorize('view', $record);

        return new RecordResource($record);
    }

    public function store(Request $request, TransitionRental $transition): RecordResource
    {
        Gate::authorize('rentals.manage');
        $data = $request->validate(['reservation_id' => ['required', 'integer', 'min:1'], 'driver_user_id' => ['sometimes', 'nullable', 'integer', 'min:1']]);

        return new RecordResource($transition->create($request->user(), $data['reservation_id'], $data['driver_user_id'] ?? null)->load(['vehicle', 'reservation.customer', 'driver', 'invoice']));
    }

    public function transition(Request $request, int $rental, string $action, TransitionRental $transition): RecordResource
    {
        $visible = OperationalAccess::rentals($request->user(), 'manage')->findOrFail($rental);
        Gate::authorize('update', $visible);
        $data = $request->validate(['extra_charge_minor' => ['sometimes', 'integer', 'min:0', 'max:100000000000000'], 'extra_charge_description' => [Rule::requiredIf((int) $request->input('extra_charge_minor', 0) > 0), 'nullable', 'string', 'max:255'], 'odometer' => ['required', 'integer', 'min:0'], 'notes' => ['required', 'string', 'max:10000'], 'vehicle_status' => [Rule::requiredIf($action === 'return'), Rule::in(['available', 'maintenance', 'damaged'])]]);

        return new RecordResource($transition->handle($request->user(), $rental, $action, $data)->load(['vehicle', 'reservation.customer', 'driver', 'invoice']));
    }

    public function extend(Request $request, int $rental, TransitionRental $transition): RecordResource
    {
        $data = $request->validate(['ends_at' => ['required', 'date', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/i'], 'notes' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        return new RecordResource($transition->extend($request->user(), $rental, $data)->load(['vehicle', 'reservation.customer', 'driver', 'invoice']));
    }

    public function assignDriver(Request $request, int $rental, TransitionRental $transition): RecordResource
    {
        $data = $request->validate(['driver_user_id' => ['present', 'nullable', 'integer', 'min:1']]);

        return new RecordResource($transition->assignDriver($request->user(), $rental, $data['driver_user_id'])->load(['vehicle', 'reservation.customer', 'driver', 'invoice']));
    }
}
