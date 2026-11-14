<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AllocateReservation;
use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Resources\RecordResource;
use App\Models\Rental;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Support\CompanyAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ReservationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return RecordResource::collection(CompanyAccess::scope(Reservation::query(), $request->user())->orderBy('id')->paginate(25));
    }

    public function show(Request $request, int $reservation): RecordResource
    {
        $record = CompanyAccess::record($request->user(), Reservation::class, $reservation);
        Gate::authorize('view', $record);

        return new RecordResource($record);
    }

    public function store(StoreReservationRequest $request, AllocateReservation $allocate): RecordResource
    {
        return new RecordResource($allocate->handle($request->user(), $request->validated()));
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

            return new RecordResource($record);
        });
    }
}
