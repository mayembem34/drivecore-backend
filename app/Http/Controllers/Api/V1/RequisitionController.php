<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\Part;
use App\Models\PurchaseRequisition;
use App\Models\Supplier;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RequisitionController extends Controller
{
    public function index(Request $r): AnonymousResourceCollection
    {
        return RecordResource::collection(DirectoryQuery::apply($r, CompanyAccess::scope(PurchaseRequisition::query(), $r->user()), ['requisition_number', 'department', 'justification'], ['status' => ['pending', 'approved', 'rejected', 'converted']])->orderByDesc('id')->paginate(25)->withQueryString());
    }

    public function store(Request $r): RecordResource
    {
        $d = $r->validate(['company_id' => ['required', 'integer', 'min:1'], 'branch_id' => ['required', 'integer', 'min:1'], 'supplier_id' => ['nullable', 'integer', 'min:1'], 'department' => ['required', 'string', 'max:60'], 'needed_by' => ['required', 'date'], 'justification' => ['required', 'string', 'max:10000'], 'items' => ['required', 'array', 'min:1', 'max:50'], 'items.*.description' => ['required', 'string', 'max:255'], 'items.*.part_id' => ['nullable', 'integer', 'min:1'], 'items.*.quantity' => ['required', 'integer', 'between:1,1000000'], 'items.*.estimated_unit_minor' => ['required', 'integer', 'between:0,1000000000']]);
        CompanyAccess::branch($r->user(), $d['company_id'], $d['branch_id']);
        if (isset($d['supplier_id'])) {
            Supplier::where('company_id', $d['company_id'])->findOrFail($d['supplier_id']);
        } $total = 0;
        foreach ($d['items'] as $item) {
            if (isset($item['part_id'])) {
                Part::where('company_id', $d['company_id'])->where('branch_id', $d['branch_id'])->findOrFail($item['part_id']);
            } $total += $item['quantity'] * $item['estimated_unit_minor'];
        } $record = PurchaseRequisition::create($d + ['requester_id' => $r->user()->id, 'requisition_number' => 'REQ-'.Str::ulid(), 'estimated_total_minor' => $total]);
        RecordAudit::write($r->user(), 'requisition.created', $record);

        return new RecordResource($record->refresh());
    }

    public function decide(Request $r, int $id, string $action): RecordResource
    {
        CompanyAccess::record($r->user(), PurchaseRequisition::class, $id);

        return DB::transaction(function () use ($r, $id, $action): RecordResource {
            $record = PurchaseRequisition::lockForUpdate()->findOrFail($id);
            abort_if($record->requester_id === $r->user()->id, 403);
            abort_unless($record->status === 'pending', 409);
            $record->update(['status' => $action === 'approve' ? 'approved' : 'rejected', 'approver_id' => $r->user()->id]);
            RecordAudit::write($r->user(), 'requisition.'.$record->status, $record);

            return new RecordResource($record);
        }, 3);
    }

    public function convert(Request $r, int $id): RecordResource
    {
        $r->validate(['supplier_id' => ['sometimes', 'integer', 'min:1']]);
        CompanyAccess::record($r->user(), PurchaseRequisition::class, $id);

        return DB::transaction(function () use ($r, $id): RecordResource {
            $req = PurchaseRequisition::lockForUpdate()->findOrFail($id);
            abort_unless($req->status === 'approved' && $req->purchase_order_id === null && $req->approver_id !== $req->requester_id, 409);
            $supplier = $r->integer('supplier_id') ?: $req->supplier_id;
            if (! $supplier) {
                throw ValidationException::withMessages(['supplier_id' => 'Select a supplier to create the purchase order.']);
            } Supplier::where('company_id', $req->company_id)->where('is_active', true)->findOrFail($supplier);
            $copy = $r->duplicate();
            $copy->setUserResolver(fn () => $r->user());
            $copy->merge(['company_id' => $req->company_id, 'branch_id' => $req->branch_id, 'supplier_id' => $supplier, 'notes' => $req->justification, 'lines' => array_map(fn (array $item) => ['part_id' => $item['part_id'] ?? null, 'description' => $item['description'], 'quantity' => $item['quantity'], 'unit_cost_minor' => $item['estimated_unit_minor']], $req->items)]);
            $po = (new PurchaseOrderController)->store($copy);
            $req->update(['status' => 'converted', 'purchase_order_id' => $po->resource->id]);
            RecordAudit::write($r->user(), 'requisition.converted', $req);

            return $po;
        }, 3);
    }
}
