<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PostJournal;
use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\ApprovalRequest;
use App\Models\BankAccount;
use App\Models\GlAccount;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayablesController extends Controller
{
    public function index(Request $r): AnonymousResourceCollection
    {
        return RecordResource::collection(DirectoryQuery::apply($r, CompanyAccess::scope(SupplierInvoice::query(), $r->user()), ['invoice_number', 'notes'], ['status' => ['draft', 'pending_approval', 'approved', 'partially_paid', 'paid', 'void'], 'match_status' => ['matched', 'variance', 'non_po']], integerFilters: ['supplier_id'])->orderByDesc('id')->paginate(25)->withQueryString());
    }

    public function store(Request $r, PostJournal $journal): RecordResource
    {
        $d = $r->validate(['company_id' => ['required', 'integer', 'min:1'], 'branch_id' => ['required', 'integer', 'min:1'], 'supplier_id' => ['required', 'integer', 'min:1'], 'purchase_order_id' => ['nullable', 'integer', 'min:1'], 'invoice_number' => ['required', 'string', 'max:64'], 'invoice_date' => ['required', 'date'], 'due_at' => ['required', 'date', 'after_or_equal:invoice_date'], 'subtotal_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'], 'vat_minor' => ['sometimes', 'integer', 'min:0', 'max:1000000000000'], 'expense_account_code' => ['nullable', 'required_without:purchase_order_id', 'string', 'max:20'], 'notes' => ['nullable', 'string', 'max:10000']]);
        CompanyAccess::branch($r->user(), $d['company_id'], $d['branch_id']);

        return DB::transaction(function () use ($r, $d, $journal): RecordResource {
            $supplier = CompanyAccess::record($r->user(), Supplier::class, $d['supplier_id']);
            abort_unless($supplier->company_id === $d['company_id'], 404);
            $total = $d['subtotal_minor'] + ($d['vat_minor'] ?? 0);
            $po = null;
            $variance = 0;
            if (isset($d['purchase_order_id'])) {
                $po = CompanyAccess::scope(PurchaseOrder::query(), $r->user())->where('company_id', $d['company_id'])->where('branch_id', $d['branch_id'])->where('supplier_id', $supplier->id)->lockForUpdate()->findOrFail($d['purchase_order_id']);
                abort_unless($po->status === 'received', 409, 'Receive the purchase order before entering its invoice.');
                abort_if(SupplierInvoice::where('purchase_order_id', $po->id)->where('status', '!=', 'void')->exists(), 409, 'This purchase order has already been invoiced.');
                $variance = $total - $po->total_minor;
            } else {
                abort_unless(GlAccount::where('company_id', $d['company_id'])->where('code', $d['expense_account_code'])->where('type', 'expense')->exists(), 422, 'Select an expense GL account.');
            }
            if (SupplierInvoice::where('company_id', $d['company_id'])->where('supplier_id', $supplier->id)->where('invoice_number', $d['invoice_number'])->exists()) {
                throw ValidationException::withMessages(['invoice_number' => 'This supplier invoice already exists.']);
            }
            $record = SupplierInvoice::create($d + ['vat_minor' => 0, 'total_minor' => $total, 'match_status' => $po ? ($variance ? 'variance' : 'matched') : 'non_po', 'variance_minor' => $variance, 'status' => $variance ? 'pending_approval' : 'approved']);
            if ($variance) {
                $a = ApprovalRequest::create(['company_id' => $record->company_id, 'branch_id' => $record->branch_id, 'type' => 'purchase', 'entity_type' => 'supplier_invoice', 'entity_id' => $record->id, 'title' => 'Supplier invoice match variance', 'requester_id' => $r->user()->id, 'previous_value' => (string) $po->total_minor, 'requested_value' => (string) $total, 'reason' => 'Three-way match variance: '.$variance]);
                $record->update(['approval_request_id' => $a->id]);
            }
            if (! $po) {
                $journal->handle($r->user(), $record->company_id, $record->branch_id, 'supplier_invoice', $record->id, [[$record->expense_account_code, $record->subtotal_minor, 0], ['2100', $record->vat_minor, 0], ['2000', 0, $total]], $record->invoice_number, 'Supplier invoice');
            }
            RecordAudit::write($r->user(), 'supplier_invoice.created', $record);

            return new RecordResource($record->refresh());
        }, 3);
    }

    public function pay(Request $r, int $id, PostJournal $journal): RecordResource
    {
        $d = $r->validate(['amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'], 'method' => ['required', Rule::in(['bank_transfer', 'cash', 'card', 'mobile_money'])], 'bank_account_id' => ['nullable', 'integer', 'min:1'], 'reference' => ['nullable', 'string', 'max:80'], 'paid_at' => ['required', 'date']]);
        CompanyAccess::record($r->user(), SupplierInvoice::class, $id);

        return DB::transaction(function () use ($r, $id, $d, $journal): RecordResource {
            $bill = SupplierInvoice::lockForUpdate()->findOrFail($id);
            abort_if(in_array($bill->status, ['draft', 'void', 'paid'], true), 409);
            if ($bill->match_status === 'variance') {
                $approval = ApprovalRequest::whereKey($bill->approval_request_id)->lockForUpdate()->first();
                abort_unless($approval && $approval->status === 'approved' && $approval->type === 'purchase' && $approval->entity_type === 'supplier_invoice' && $approval->entity_id === $bill->id && $approval->requester_id !== $approval->approver_id && (int) $approval->approved_value === $bill->total_minor, 409, 'Approve the match variance first.');
                if ($bill->status === 'pending_approval') {
                    $delta = $bill->variance_minor;
                    $journal->handle($r->user(), $bill->company_id, $bill->branch_id, 'supplier_invoice', $bill->id, $delta > 0 ? [['5000', $delta, 0], ['2000', 0, $delta]] : [['2000', -$delta, 0], ['5000', 0, -$delta]], $bill->invoice_number, 'Approved invoice variance');
                    $bill->status = 'approved';
                }
            }
            if ($d['amount_minor'] > $bill->total_minor - $bill->paid_minor) {
                throw ValidationException::withMessages(['amount_minor' => 'Payment exceeds the unpaid balance.']);
            }
            $account = isset($d['bank_account_id']) ? CompanyAccess::record($r->user(), BankAccount::class, $d['bank_account_id']) : null;
            if ($account) {
                abort_unless($account->company_id === $bill->company_id && ($account->branch_id === null || $account->branch_id === $bill->branch_id) && $account->is_active, 404);
            }
            $payment = SupplierPayment::create($d + ['company_id' => $bill->company_id, 'supplier_invoice_id' => $bill->id]);
            $entry = $journal->handle($r->user(), $bill->company_id, $bill->branch_id, 'supplier_payment', $payment->id, [['2000', $d['amount_minor'], 0], [$account?->gl_account_code ?? '1000', 0, $d['amount_minor']]], $d['reference'] ?? $bill->invoice_number, 'Supplier payment');
            $payment->update(['journal_entry_id' => $entry->id]);
            $bill->paid_minor += $d['amount_minor'];
            $bill->status = $bill->paid_minor === $bill->total_minor ? 'paid' : 'partially_paid';
            $bill->save();
            RecordAudit::write($r->user(), 'supplier_invoice.payment', $bill);

            return new RecordResource($payment->refresh());
        }, 3);
    }

    public function ageing(Request $r): JsonResponse
    {
        $q = DirectoryQuery::apply($r, CompanyAccess::scope(SupplierInvoice::query(), $r->user()), [])->whereNotIn('status', ['draft', 'void'])->whereColumn('paid_minor', '<', 'total_minor');
        $buckets = array_fill_keys(['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'], 0);
        $rows = [];
        foreach ($q->lazyById(100) as $bill) {
            $age = $bill->due_at->diffInDays(now(), false);
            $key = $age <= 0 ? 'current' : ($age <= 30 ? 'd1_30' : ($age <= 60 ? 'd31_60' : ($age <= 90 ? 'd61_90' : 'd90_plus')));
            $out = $bill->total_minor - $bill->paid_minor;
            $buckets[$key] += $out;
            $rows[] = ['id' => $bill->id, 'supplier_id' => $bill->supplier_id, 'invoice_number' => $bill->invoice_number, 'outstanding_minor' => $out, 'bucket' => $key];
        }

        return response()->json(['data' => ['buckets' => $buckets, 'total_outstanding_minor' => array_sum($buckets), 'rows' => $rows]]);
    }

    public function statement(Request $r, int $id): JsonResponse
    {
        $s = CompanyAccess::record($r->user(), Supplier::class, $id);
        $bills = CompanyAccess::scope(SupplierInvoice::query(), $r->user())->where('supplier_id', $id)->whereNotIn('status', ['draft', 'void'])->get();
        $rows = [];
        foreach ($bills as $b) {
            $rows[] = ['type' => 'invoice', 'id' => $b->id, 'date' => $b->invoice_date, 'reference' => $b->invoice_number, 'debit_minor' => $b->total_minor, 'credit_minor' => 0];
        } foreach (SupplierPayment::whereIn('supplier_invoice_id', $bills->pluck('id'))->get() as $pay) {
            $rows[] = ['type' => 'payment', 'id' => $pay->id, 'date' => $pay->paid_at, 'reference' => $pay->reference, 'debit_minor' => 0, 'credit_minor' => $pay->amount_minor];
        } $balance = 0;
        $rows = collect($rows)->sortBy([['date', 'asc'], ['type', 'asc'], ['id', 'asc']])->values()->map(function (array $row) use (&$balance): array {
            $balance += $row['debit_minor'] - $row['credit_minor'];

            return $row + ['balance_minor' => $balance];
        })->all();

        return response()->json(['data' => ['supplier_id' => $s->id, 'rows' => $rows, 'closing_balance_minor' => $balance]]);
    }
}
