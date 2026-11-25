<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\Rental;
use App\Models\Vehicle;
use App\Models\VehicleIncident;
use App\Models\WorkOrder;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IncidentController extends Controller
{
    public function index(Request $r): AnonymousResourceCollection
    {
        return RecordResource::collection(DirectoryQuery::apply($r, CompanyAccess::scope(VehicleIncident::query(), $r->user()), ['description', 'location'], ['status' => ['reported', 'assessing', 'repairing', 'closed']], integerFilters: ['vehicle_id'])->orderByDesc('id')->paginate(25)->withQueryString());
    }

    public function save(Request $r, ?int $id = null): RecordResource
    {
        $required = $id ? 'sometimes' : 'required';
        $d = $r->validate(['company_id' => [$id ? 'prohibited' : 'required', 'integer', 'min:1'], 'branch_id' => [$id ? 'prohibited' : 'required', 'integer', 'min:1'], 'vehicle_id' => [$id ? 'prohibited' : 'required', 'integer', 'min:1'], 'rental_id' => ['nullable', 'integer', 'min:1'], 'incident_type' => [$required, Rule::in(['accident', 'breakdown', 'damage', 'theft_vandalism'])], 'severity' => [$required, Rule::in(['minor', 'moderate', 'major', 'total_loss'])], 'occurred_at' => [$required, 'date'], 'location' => ['nullable', 'string', 'max:160'], 'driver_name' => ['nullable', 'string', 'max:120'], 'insurance_claim_number' => ['nullable', 'string', 'max:64'], 'estimated_cost_minor' => ['nullable', 'integer', 'min:0', 'max:1000000000000'], 'description' => [$required, 'string', 'max:10000'], 'status' => ['sometimes', Rule::in(['reported', 'assessing', 'repairing', 'closed'])], 'mark_unavailable' => ['sometimes', 'boolean']]);
        $visible = $id ? CompanyAccess::record($r->user(), VehicleIncident::class, $id) : null;
        $company = $visible?->company_id ?? $d['company_id'];
        $branch = $visible?->branch_id ?? $d['branch_id'];
        CompanyAccess::branch($r->user(), $company, $branch);

        return DB::transaction(function () use ($r, $id, $d, $visible, $company, $branch): RecordResource {
            $v = Vehicle::where('company_id', $company)->where('branch_id', $branch)->lockForUpdate()->findOrFail($visible?->vehicle_id ?? $d['vehicle_id']);
            if (isset($d['rental_id'])) {
                Rental::where('company_id', $company)->where('branch_id', $branch)->where('vehicle_id', $v->id)->findOrFail($d['rental_id']);
            } $record = $id ? VehicleIncident::lockForUpdate()->findOrFail($id) : new VehicleIncident;
            $record->fill(collect($d)->except('mark_unavailable')->all())->save();
            if (($d['mark_unavailable'] ?? ! $id) && ! in_array($v->status, ['sold', 'decommissioned'], true)) {
                $v->update(['status' => $record->incident_type === 'accident' ? 'accident' : 'awaiting_repair']);
            } RecordAudit::write($r->user(), 'incident.saved', $record);

            return new RecordResource($record->refresh());
        }, 3);
    }

    public function createWorkOrder(Request $r, int $id): RecordResource
    {
        $visible = CompanyAccess::record($r->user(), VehicleIncident::class, $id);

        return DB::transaction(function () use ($r, $visible): RecordResource {
            Vehicle::whereKey($visible->vehicle_id)->lockForUpdate()->firstOrFail();
            $incident = VehicleIncident::lockForUpdate()->findOrFail($visible->id);
            abort_if($incident->work_order_id !== null || $incident->status === 'closed', 409);
            $work = WorkOrder::create(['company_id' => $incident->company_id, 'branch_id' => $incident->branch_id, 'vehicle_id' => $incident->vehicle_id, 'created_by' => $r->user()->id, 'title' => 'Incident #'.$incident->id.' '.$incident->incident_type, 'status' => 'open', 'due_at' => now(), 'complaint' => $incident->description, 'estimate_minor' => $incident->estimated_cost_minor]);
            $incident->update(['work_order_id' => $work->id, 'status' => 'repairing']);
            RecordAudit::write($r->user(), 'incident.work_order_created', $incident);

            return new RecordResource($work->refresh()->load(['vehicle', 'movements']));
        }, 3);
    }
}
