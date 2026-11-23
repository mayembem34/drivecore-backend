<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AllocateReservation;
use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Resources\RecordResource;
use App\Models\Customer;
use App\Models\Rental;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ReservationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return RecordResource::collection(DirectoryQuery::apply($request, CompanyAccess::scope(Reservation::query(), $request->user()), ['id', 'customer:name', 'vehicle:registration'], ['status' => ['confirmed', 'cancelled', 'completed']], integerFilters: ['vehicle_id', 'customer_id'], sortColumns: ['id' => 'id', 'starts_at' => 'starts_at', 'status' => 'status', 'customer_name' => Customer::select('name')->whereColumn('customers.id', 'reservations.customer_id')->limit(1), 'vehicle_registration' => Vehicle::select('registration')->whereColumn('vehicles.id', 'reservations.vehicle_id')->limit(1)])->with(['customer', 'vehicle', 'rental'])->orderBy('id')->paginate(25)->withQueryString());
    }

    public function show(Request $request, int $reservation): RecordResource
    {
        $record = CompanyAccess::record($request->user(), Reservation::class, $reservation);
        Gate::authorize('view', $record);

        return new RecordResource($record->load(['customer', 'vehicle', 'rental']));
    }

    public function store(StoreReservationRequest $request, AllocateReservation $allocate): RecordResource
    {
        return new RecordResource($allocate->handle($request->user(), $request->validated())->load(['customer', 'vehicle', 'rental']));
    }

    public function cancel(Request $request, int $reservation): RecordResource
    {
        $visible = CompanyAccess::record($request->user(), Reservation::class, $reservation);

        return DB::transaction(function () use ($request, $visible): RecordResource {
            Vehicle::whereKey($visible->vehicle_id)->lockForUpdate()->firstOrFail();
            $record = Reservation::lockForUpdate()->findOrFail($visible->id);
            Gate::authorize('update', $record);
            abort_if(Rental::where('reservation_id', $record->id)->exists(), 409, 'A reservation with a rental cannot be cancelled.');
            abort_unless($record->status === 'confirmed', 409, 'Reservation is not confirmed.');
            $record->update(['status' => 'cancelled']);
            RecordAudit::write($request->user(), 'reservation.cancelled', $record);

            return new RecordResource($record->load(['customer', 'vehicle', 'rental']));
        });
    }
}
