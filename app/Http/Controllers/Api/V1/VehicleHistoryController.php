<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\Document;
use App\Models\FuelTransaction;
use App\Models\Rental;
use App\Models\Vehicle;
use App\Models\VehicleIncident;
use App\Models\WorkOrder;
use App\Support\CompanyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleHistoryController extends Controller
{
    public function __invoke(Request $r, int $id): JsonResponse
    {
        $v = CompanyAccess::record($r->user(), Vehicle::class, $id);
        $lists = [];
        foreach (['rentals' => [Rental::class, 'rentals.view', ['reservation.customer', 'vehicle', 'driver', 'invoice']], 'fuel' => [FuelTransaction::class, 'fuel.view', []], 'work_orders' => [WorkOrder::class, 'work_orders.view', ['vehicle', 'movements']], 'incidents' => [VehicleIncident::class, 'vehicles.view', []]] as $key => [$model,$permission,$with]) {
            $lists[$key] = $r->user()->can($permission) ? RecordResource::collection($model::where('vehicle_id', $id)->with($with)->orderByDesc('id')->limit(100)->get())->resolve($r) : [];
        } $lists['compliance'] = $r->user()->can('documents.view') ? RecordResource::collection(Document::where('company_id', $v->company_id)->where('branch_id', $v->branch_id)->where('entity_type', 'vehicle')->where('entity_id', $id)->orderByDesc('id')->limit(100)->get())->resolve($r) : [];

        return response()->json(['data' => ['vehicle_id' => $id] + $lists]);
    }
}
