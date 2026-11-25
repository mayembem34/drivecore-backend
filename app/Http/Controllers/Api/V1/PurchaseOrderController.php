<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\MoveStock;
use App\Actions\PostJournal;
use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\ApprovalRequest;
use App\Models\Expense;
use App\Models\Part;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    private function resource(PurchaseOrder $record): RecordResource
    {
        return new RecordResource($record->load(['lines', 'supplier']));
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return RecordResource::collection(DirectoryQuery::apply($request, CompanyAccess::scope(PurchaseOrder::query(), $request->user()), ['po_number', 'supplier:name', 'notes'], ['status' => ['draft', 'submitted', 'approved', 'received', 'cancelled']], integerFilters: ['supplier_id'], sortColumns: ['id' => 'id', 'total_minor' => 'total_minor', 'status' => 'status', 'created_at' => 'created_at', 'supplier_name' => Supplier::select('name')->whereColumn('suppliers.id', 'purchase_orders.supplier_id')->limit(1)])->with(['lines', 'supplier'])->orderByDesc('id')->paginate(25)->withQueryString());
    }

    public function show(Request $request, int $purchase_order): RecordResource
    {
        $record = CompanyAccess::record($request->user(), PurchaseOrder::class, $purchase_order);
        Gate::authorize('view', $record);

        return $this->resource($record);
    }

    public function store(Request $request): RecordResource
    {
        return $this->save($request);
    }

    public function update(Request $request, int $purchase_order): RecordResource
    {
        return $this->save($request, $purchase_order);
    }

    private function save(Request $request, ?int $id = null): RecordResource
    {
        $data = $request->validate(['company_id' => [$id === null ? 'required' : 'prohibited', 'integer', 'min:1'], 'branch_id' => [$id === null ? 'required' : 'prohibited', 'integer', 'min:1'], 'supplier_id' => [$id === null ? 'required' : 'sometimes', 'integer', 'min:1'], 'notes' => ['sometimes', 'nullable', 'string', 'max:10000'], 'lines' => ['required', 'array', 'min:1', 'max:50'], 'lines.*.description' => ['nullable', 'required_without:lines.*.part_id', 'string', 'max:255'], 'lines.*.part_id' => ['nullable', 'required_without:lines.*.description', 'integer', 'min:1', 'distinct'], 'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'], 'lines.*.unit_cost_minor' => ['required', 'integer', 'min:0', 'max:1000000000']]);
        if ($id !== null) {
            CompanyAccess::record($request->user(), PurchaseOrder::class, $id);
        }

        return DB::transaction(function () use ($request, $data, $id): RecordResource {
            $record = $id === null ? new PurchaseOrder(['company_id' => $data['company_id'], 'branch_id' => $data['branch_id'], 'created_by' => $request->user()->id, 'po_number' => 'PO-'.Str::ulid(), 'status' => 'draft']) : PurchaseOrder::lockForUpdate()->findOrFail($id);
            if ($id !== null) {
                Gate::authorize('update', $record);
            }abort_unless($record->status === 'draft', 409);
            CompanyAccess::branch($request->user(), $record->company_id, $record->branch_id);
            $supplier = Supplier::where('company_id', $record->company_id)->where('is_active', true)->find($data['supplier_id'] ?? $record->supplier_id);
            if (! $supplier) {
                throw ValidationException::withMessages(['supplier_id' => 'Select an active supplier in this company.']);
            }
            $ids = array_values(array_filter(array_column($data['lines'], 'part_id')));
            if (Part::where('company_id', $record->company_id)->where('branch_id', $record->branch_id)->whereIn('id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['lines' => 'All parts must belong to this branch.']);
            }
            $total = 0;
            $lines = [];
            foreach ($data['lines'] as $line) {
                $amount = $line['quantity'] * $line['unit_cost_minor'];
                $total += $amount;
                $lines[] = [...$line, 'line_total_minor' => $amount];
            }
            $record->fill(['supplier_id' => $supplier->id, 'total_minor' => $total, 'notes' => $data['notes'] ?? $record->notes])->save();
            $record->lines()->delete();
            $record->lines()->createMany($lines);
            RecordAudit::write($request->user(), $id === null ? 'purchase_order.created' : 'purchase_order.updated', $record);

            return $this->resource($record);
        }, 3);
    }

    public function transition(Request $request, int $purchase_order, string $action, MoveStock $move, PostJournal $journal): RecordResource
    {
        CompanyAccess::record($request->user(), PurchaseOrder::class, $purchase_order);

        return DB::transaction(function () use ($request, $purchase_order, $action, $move, $journal): RecordResource {
            $record = PurchaseOrder::lockForUpdate()->findOrFail($purchase_order);
            if ($action === 'approve') {
                Gate::authorize('purchase_orders.approve');
                Gate::authorize('view', $record);
            } else {
                Gate::authorize('update', $record);
            }
            if ($action === 'submit') {
                abort_unless($record->status === 'draft', 409);
                $record->update(['status' => 'submitted', 'submitted_at' => now()]);
                ApprovalRequest::create(['company_id' => $record->company_id, 'branch_id' => $record->branch_id, 'type' => 'purchase', 'entity_type' => 'purchase_order', 'entity_id' => $record->id, 'title' => 'Purchase order '.$record->po_number, 'requester_id' => $request->user()->id, 'requested_value' => (string) $record->total_minor, 'reason' => 'Purchase approval']);
            } elseif ($action === 'approve') {
                abort_unless($record->status === 'submitted', 409);
                abort_if($record->created_by === $request->user()->id, 403);
                $approval = ApprovalRequest::where('company_id', $record->company_id)->where('type', 'purchase')->where('entity_type', 'purchase_order')->where('entity_id', $record->id)->where('status', 'pending')->latest('id')->firstOrFail();
                (new ApprovalController)->decide($request, $approval->id, 'approve');
                $record->refresh();
            } elseif ($action === 'receive') {
                abort_unless($record->status === 'approved', 409);
                $lines = $record->lines()->orderBy('part_id')->get();
                $inventory = 0;
                $other = 0;
                foreach ($lines as $line) {
                    if ($line->part_id === null) {
                        $other += $line->line_total_minor;

                        continue;
                    }
                    $inventory += $line->line_total_minor;
                    $part = Part::where('company_id', $record->company_id)->where('branch_id', $record->branch_id)->lockForUpdate()->findOrFail($line->part_id);
                    $move->handle($request->user(), $part, $line->quantity, $line->unit_cost_minor, 'Purchase receipt', purchaseOrder: $record->id);
                }$record->update(['status' => 'received', 'received_at' => now()]);
                if ($other > 0) {
                    Expense::create(['company_id' => $record->company_id, 'branch_id' => $record->branch_id, 'recorded_by' => $request->user()->id, 'category' => 'procurement', 'description' => $record->po_number, 'amount_minor' => $other, 'incurred_at' => now()]);
                }
                $journal->handle($request->user(), $record->company_id, $record->branch_id, 'purchase_order', $record->id, [['1200', $inventory, 0], ['5000', $other, 0], ['2000', 0, $record->total_minor]], $record->po_number, 'Purchase received');
            } else {
                abort_unless(in_array($record->status, ['draft', 'submitted', 'approved'], true), 409);
                $record->update(['status' => 'cancelled']);
            }
            RecordAudit::write($request->user(), 'purchase_order.'.$action, $record);

            return $this->resource($record);
        }, 3);
    }
}
