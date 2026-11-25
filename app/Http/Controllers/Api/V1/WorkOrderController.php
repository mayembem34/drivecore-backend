<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\MoveStock;
use App\Actions\PostJournal;
use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\Expense;
use App\Models\Part;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class WorkOrderController extends Controller
{
    private function resource(WorkOrder $record): RecordResource
    {
        return new RecordResource($record->refresh()->load(['vehicle', 'movements']));
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CompanyAccess::scope(WorkOrder::query(), $request->user())->select('work_orders.*')->selectRaw('labour_cost_minor + parts_cost_minor AS total_cost_minor');

        return RecordResource::collection(DirectoryQuery::apply($request, $query, ['title', 'vehicle:registration'], ['status' => ['open', 'in_progress', 'completed', 'cancelled']], integerFilters: ['vehicle_id'], sortColumns: ['id' => 'id', 'title' => 'title', 'due_at' => 'due_at', 'total_cost_minor' => 'total_cost_minor', 'status' => 'status', 'vehicle_registration' => Vehicle::select('registration')->whereColumn('vehicles.id', 'work_orders.vehicle_id')->limit(1)])->with(['vehicle', 'movements'])->orderByDesc('id')->paginate(25)->withQueryString());
    }

    public function show(Request $request, int $work_order): RecordResource
    {
        $record = CompanyAccess::record($request->user(), WorkOrder::class, $work_order);
        Gate::authorize('view', $record);

        return $this->resource($record);
    }

    public function store(Request $request): RecordResource
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1'], 'branch_id' => ['required', 'integer', 'min:1'], 'vehicle_id' => ['required', 'integer', 'min:1'], 'title' => ['required', 'string', 'max:255'], 'due_at' => ['required', 'date'], 'labour_cost_minor' => ['sometimes', 'integer', 'min:0', 'max:1000000000000'], 'notes' => ['sometimes', 'nullable', 'string', 'max:10000']]);
        CompanyAccess::branch($request->user(), $data['company_id'], $data['branch_id']);
        CompanyAccess::scope(Vehicle::query(), $request->user())->where('company_id', $data['company_id'])->where('branch_id', $data['branch_id'])->findOrFail($data['vehicle_id']);
        $record = WorkOrder::create([...$data, 'created_by' => $request->user()->id, 'status' => 'open']);
        RecordAudit::write($request->user(), 'work_order.created', $record);

        return $this->resource($record);
    }

    public function transition(Request $request, int $work_order, string $action, MoveStock $move, PostJournal $journal): RecordResource
    {
        $data = match ($action) {
            'consume-parts' => $request->validate(['part_id' => ['required', 'integer', 'min:1'], 'quantity' => ['required', 'integer', 'min:1', 'max:1000000']]),'complete' => $request->validate(['labour_cost_minor' => ['sometimes', 'integer', 'min:0', 'max:1000000000000'], 'completion_notes' => ['sometimes', 'nullable', 'string', 'max:10000']]),default => []
        };
        $visible = CompanyAccess::record($request->user(), WorkOrder::class, $work_order);

        return DB::transaction(function () use ($request, $work_order, $visible, $action, $data, $move, $journal): RecordResource {
            $vehicle = Vehicle::whereKey($visible->vehicle_id)->lockForUpdate()->firstOrFail();
            $record = WorkOrder::lockForUpdate()->findOrFail($work_order);
            Gate::authorize('update', $record);
            if ($action === 'start') {
                abort_unless($record->status === 'open', 409);
                abort_if($vehicle->status === 'rented' || Reservation::where('vehicle_id', $vehicle->id)->where('status', 'confirmed')->where('ends_at', '>', now())->exists() || WorkOrder::where('vehicle_id', $vehicle->id)->where('status', 'in_progress')->whereKeyNot($record->id)->exists(), 409, 'Vehicle is rented, reserved, or already in maintenance.');
                $record->update(['status' => 'in_progress', 'started_at' => now()]);
                $vehicle->update(['status' => 'maintenance']);
            } elseif ($action === 'consume-parts') {
                abort_unless($record->status === 'in_progress', 409);
                $part = CompanyAccess::scope(Part::query(), $request->user())->where('company_id', $record->company_id)->where('branch_id', $record->branch_id)->lockForUpdate()->find($data['part_id']);
                if (! $part) {
                    throw ValidationException::withMessages(['part_id' => 'Select a part in this work order branch.']);
                }$movement = $move->handle($request->user(), $part, -(int) $data['quantity'], $part->unit_cost_minor, 'Maintenance consumption', $record->id);
                $cost = $data['quantity'] * $part->unit_cost_minor;
                $record->increment('parts_cost_minor', $cost);
                $journal->handle($request->user(), $record->company_id, $record->branch_id, 'work_order', $record->id, [['1250', $cost, 0], ['1200', 0, $cost]], 'WO-'.$record->id, 'Parts consumed');
            } elseif ($action === 'complete') {
                abort_unless($record->status === 'in_progress', 409);
                $record->fill($data);
                $total = $record->labour_cost_minor + $record->parts_cost_minor;
                if ($total > 0) {
                    $expense = Expense::create(['company_id' => $record->company_id, 'branch_id' => $record->branch_id, 'vehicle_id' => $vehicle->id, 'recorded_by' => $request->user()->id, 'category' => 'maintenance', 'description' => $record->title, 'amount_minor' => $total, 'incurred_at' => now()]);
                    $record->expense_id = $expense->id;
                    $journal->handle($request->user(), $record->company_id, $record->branch_id, 'work_order', $record->id, [['5000', $total, 0], ['1250', 0, $record->parts_cost_minor], ['1000', 0, $record->labour_cost_minor]], 'WO-'.$record->id, 'Maintenance completed');
                }$record->fill(['status' => 'completed', 'completed_at' => now()])->save();
                $vehicle->update(['status' => 'available']);
            } else {
                abort_unless($record->status === 'open', 409);
                $record->update(['status' => 'cancelled']);
            }
            RecordAudit::write($request->user(), 'work_order.'.str_replace('-', '_', $action), $record);

            return $this->resource($record);
        }, 3);
    }
}
