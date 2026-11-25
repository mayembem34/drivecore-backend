<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\ApprovalRequest;
use App\Models\ApprovalThreshold;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Rental;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApprovalController extends Controller
{
    public const TYPES = ['discount', 'credit_limit', 'vehicle_repair', 'purchase', 'refund_writeoff', 'contract_amendment', 'vehicle_disposal'];

    public const TARGETS = ['customer' => Customer::class, 'invoice' => Invoice::class, 'quotation' => Quotation::class, 'rental' => Rental::class, 'vehicle' => Vehicle::class, 'work_order' => WorkOrder::class, 'purchase_order' => PurchaseOrder::class];

    public function index(Request $r): AnonymousResourceCollection
    {
        return RecordResource::collection(DirectoryQuery::apply($r, CompanyAccess::scope(ApprovalRequest::query(), $r->user()), ['title', 'reason'], ['type' => self::TYPES, 'status' => ['pending', 'approved', 'rejected', 'cancelled']])->orderByDesc('id')->paginate(25)->withQueryString());
    }

    public function store(Request $r): RecordResource
    {
        $d = $r->validate(['company_id' => ['required', 'integer', 'min:1'], 'branch_id' => ['nullable', 'integer', 'min:1'], 'type' => ['required', Rule::in(self::TYPES)], 'entity_type' => ['nullable', 'required_with:entity_id', Rule::in(array_keys(self::TARGETS))], 'entity_id' => ['nullable', 'required_with:entity_type', 'integer', 'min:1'], 'title' => ['required', 'string', 'max:160'], 'previous_value' => ['nullable', 'string', 'max:120'], 'requested_value' => ['required', 'string', 'max:120'], 'reason' => ['required', 'string', 'max:10000']]);
        $c = CompanyAccess::company($r->user(), $d['company_id']);
        if (isset($d['branch_id'])) {
            CompanyAccess::branch($r->user(), $c->id, $d['branch_id']);
        } else {
            abort_unless($r->user()->is_platform_admin || $c->pivot->branch_id === null, 403);
        }
        if (isset($d['entity_type'])) {
            $record = CompanyAccess::record($r->user(), self::TARGETS[$d['entity_type']], $d['entity_id']);
            abort_unless($record->company_id === $c->id && ($record->branch_id ?? null) === ($d['branch_id'] ?? null), 404);
            Gate::authorize('view', $record);
        }
        $a = ApprovalRequest::create($d + ['requester_id' => $r->user()->id]);
        RecordAudit::write($r->user(), 'approval.requested', $a);

        return new RecordResource($a->refresh());
    }

    public function decide(Request $r, int $id, string $action): RecordResource
    {
        $d = $r->validate(['approved_value' => ['sometimes', 'string', 'max:120'], 'decision_notes' => ['nullable', 'string', 'max:10000']]);
        CompanyAccess::record($r->user(), ApprovalRequest::class, $id);

        return DB::transaction(function () use ($r, $id, $action, $d): RecordResource {
            $a = ApprovalRequest::lockForUpdate()->findOrFail($id);
            abort_if($a->requester_id === $r->user()->id, 403, 'A different approver must make this decision.');
            abort_unless($a->status === 'pending', 409);
            $threshold = ApprovalThreshold::where('company_id', $a->company_id)->where('type', $a->type)->where('is_active', true)->first();
            abort_if($threshold && ! $r->user()->is_platform_admin && ! $r->user()->hasRole($threshold->approver_role), 403, 'This approval requires the configured approver role.');
            if ($action === 'approve' && $a->type !== 'contract_amendment') {
                $value = $d['approved_value'] ?? $a->requested_value;
                if (! is_numeric($value) || (float) $value < 0 || (float) $value > ($a->type === 'discount' ? 100 : 9007199254740991) || ($a->type !== 'discount' && ! ctype_digit($value))) {
                    throw ValidationException::withMessages(['approved_value' => 'Provide a valid non-negative approval value.']);
                }
            }
            $a->update(['status' => $action === 'approve' ? 'approved' : 'rejected', 'approver_id' => $r->user()->id, 'approved_value' => $action === 'approve' ? ($d['approved_value'] ?? $a->requested_value) : null, 'decision_notes' => $d['decision_notes'] ?? null, 'decided_at' => now()]);
            if ($a->type === 'discount' && $a->entity_type === 'quotation') {
                $quote = Quotation::where('company_id', $a->company_id)->where('branch_id', $a->branch_id)->lockForUpdate()->findOrFail($a->entity_id);
                abort_unless($quote->status === 'pending_approval', 409);
                abort_if($action === 'approve' && (float) $a->approved_value !== (float) $quote->discount_pct, 422, 'The approved discount must match the priced quotation.');
                $quote->update(['status' => $action === 'approve' ? 'approved' : 'rejected', 'discount_status' => $action === 'approve' ? 'approved' : 'rejected', 'discount_decided_by' => $r->user()->id, 'discount_decided_at' => now(), 'discount_decision_notes' => $a->decision_notes]);
            }
            if ($a->type === 'purchase' && $a->entity_type === 'purchase_order') {
                $po = PurchaseOrder::where('company_id', $a->company_id)->where('branch_id', $a->branch_id)->lockForUpdate()->findOrFail($a->entity_id);
                abort_unless($po->status === 'submitted', 409);
                abort_if($action === 'approve' && (int) $a->approved_value !== $po->total_minor, 422);
                $po->update(['status' => $action === 'approve' ? 'approved' : 'draft', 'approved_by' => $action === 'approve' ? $r->user()->id : null, 'approved_at' => $action === 'approve' ? now() : null]);
            }
            RecordAudit::write($r->user(), 'approval.'.$a->status, $a);

            return new RecordResource($a);
        }, 3);
    }
}
