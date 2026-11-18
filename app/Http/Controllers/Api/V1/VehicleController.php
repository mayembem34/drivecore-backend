<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveVehicleRequest;
use App\Http\Resources\RecordResource;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VehicleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return RecordResource::collection(DirectoryQuery::apply($request, CompanyAccess::scope(Vehicle::query(), $request->user()), ['registration', 'make', 'model'], ['status' => ['available', 'rented', 'maintenance', 'damaged']])->orderBy('id')->paginate(25)->withQueryString());
    }

    public function show(Request $request, int $vehicle): RecordResource
    {
        $record = CompanyAccess::record($request->user(), Vehicle::class, $vehicle);
        Gate::authorize('view', $record);

        return new RecordResource($record);
    }

    public function store(SaveVehicleRequest $request): RecordResource
    {
        $data = $request->safe()->except('odometer_unit');
        CompanyAccess::branch($request->user(), $data['company_id'], $data['branch_id']);
        Validator::make($data, ['registration' => [Rule::unique('vehicles')->where('company_id', $data['company_id'])]])->validate();

        return DB::transaction(function () use ($request, $data): RecordResource {
            $record = Vehicle::create($data)->refresh();
            RecordAudit::write($request->user(), 'vehicle.created', $record);

            return new RecordResource($record);
        });
    }

    public function update(SaveVehicleRequest $request, int $vehicle): RecordResource
    {
        return DB::transaction(function () use ($request, $vehicle): RecordResource {
            $record = CompanyAccess::scope(Vehicle::query(), $request->user())->lockForUpdate()->findOrFail($vehicle);
            Gate::authorize('update', $record);
            $data = $request->safe()->except('odometer_unit');
            if ($record->status === 'rented') {
                abort(409, 'A rented vehicle cannot be edited.');
            }
            if (isset($data['odometer']) && $data['odometer'] < $record->odometer) {
                throw ValidationException::withMessages(['odometer' => 'Odometer cannot decrease.']);
            }
            if (isset($data['status']) && $data['status'] !== 'available' && Reservation::where('vehicle_id', $record->id)->where('status', 'confirmed')->where('ends_at', '>', now())->exists()) {
                abort(409, 'Cancel pending reservations before restricting this vehicle.');
            }
            Validator::make($data, ['registration' => [Rule::unique('vehicles')->where('company_id', $record->company_id)->ignore($record->id)]])->validate();
            $record->update($data);
            RecordAudit::write($request->user(), 'vehicle.updated', $record);

            return new RecordResource($record);
        });
    }
}
