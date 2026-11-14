<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\TransitionRental;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\Rental;
use App\Support\CompanyAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RentalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return RecordResource::collection(CompanyAccess::scope(Rental::query(), $request->user())->orderBy('id')->paginate(25));
    }

    public function show(Request $request, int $rental): RecordResource
    {
        $record = CompanyAccess::record($request->user(), Rental::class, $rental);
        Gate::authorize('view', $record);

        return new RecordResource($record);
    }

    public function store(Request $request, TransitionRental $transition): RecordResource
    {
        $data = $request->validate(['reservation_id' => ['required', 'integer']]);

        return new RecordResource($transition->create($request->user(), $data['reservation_id']));
    }

    public function transition(Request $request, int $rental, string $action, TransitionRental $transition): RecordResource
    {
        $data = $request->validate(['odometer' => ['required', 'integer', 'min:0'], 'notes' => ['required', 'string', 'max:10000'], 'vehicle_status' => [Rule::requiredIf($action === 'return'), Rule::in(['available', 'maintenance', 'damaged'])]]);

        return new RecordResource($transition->handle($request->user(), $rental, $action, $data));
    }
}
