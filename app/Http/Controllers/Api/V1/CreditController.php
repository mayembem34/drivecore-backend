<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\CollectionNote;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\ApprovalRules;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CreditController extends Controller
{
    public function update(Request $r, int $id): RecordResource
    {
        $d = $r->validate(['approval_request_id' => ['nullable', 'integer', 'min:1'], 'credit_limit_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000000'], 'payment_terms_days' => ['sometimes', 'integer', 'between:0,3650'], 'is_on_credit_hold' => ['sometimes', 'boolean'], 'credit_hold_reason' => ['nullable', 'required_if:is_on_credit_hold,true', 'string', 'max:255']]);
        CompanyAccess::record($r->user(), Customer::class, $id);

        return DB::transaction(function () use ($r, $id, $d): RecordResource {
            $c = Customer::lockForUpdate()->findOrFail($id);
            if (isset($d['credit_limit_minor'])) {
                ApprovalRules::check($r->user(), $c, 'credit_limit', 'customer', $d['credit_limit_minor'], $d['approval_request_id'] ?? null);
            }
            $c->update(collect($d)->except('approval_request_id')->all());
            RecordAudit::write($r->user(), 'customer.credit_updated', $c);

            return new RecordResource($c);
        }, 3);
    }

    public function notes(Request $r): AnonymousResourceCollection
    {
        return RecordResource::collection(DirectoryQuery::apply($r, CompanyAccess::scope(CollectionNote::query(), $r->user()), ['notes'], ['note_type' => ['reminder', 'promise_to_pay', 'dispute', 'escalation', 'general'], 'status' => ['open', 'kept', 'broken', 'resolved']], integerFilters: ['customer_id'])->orderByDesc('id')->paginate(25)->withQueryString());
    }

    public function saveNote(Request $r, ?int $id = null): RecordResource
    {
        $d = $r->validate(['customer_id' => [$id ? 'sometimes' : 'required', 'integer', 'min:1'], 'note_type' => [$id ? 'sometimes' : 'required', Rule::in(['reminder', 'promise_to_pay', 'dispute', 'escalation', 'general'])], 'promised_amount_minor' => ['nullable', 'required_if:note_type,promise_to_pay', 'integer', 'min:1'], 'promised_date' => ['nullable', 'required_if:note_type,promise_to_pay', 'date'], 'status' => ['sometimes', Rule::in(['open', 'kept', 'broken', 'resolved'])], 'notes' => [$id ? 'sometimes' : 'required', 'string', 'max:10000']]);
        $note = $id ? CompanyAccess::record($r->user(), CollectionNote::class, $id) : null;
        $c = CompanyAccess::record($r->user(), Customer::class, $note?->customer_id ?? $d['customer_id']);
        $record = $note ?? new CollectionNote(['company_id' => $c->company_id, 'branch_id' => $c->branch_id, 'created_by' => $r->user()->id]);
        $record->fill($d)->save();
        RecordAudit::write($r->user(), 'collection_note.saved', $record);

        return new RecordResource($record->refresh());
    }

    public function statement(Request $r, int $id): JsonResponse
    {
        $c = CompanyAccess::record($r->user(), Customer::class, $id);
        $invoices = CompanyAccess::scope(Invoice::query(), $r->user())->where('customer_id', $id)->where('status', 'issued')->get();
        $ids = $invoices->pluck('id');
        $rows = [];
        foreach ($invoices as $i) {
            $rows[] = ['type' => 'invoice', 'id' => $i->id, 'date' => $i->issued_at ?? $i->created_at, 'reference' => $i->invoice_number, 'amount_minor' => $i->total_minor, 'debit_minor' => $i->total_minor, 'credit_minor' => 0];
        }
        foreach (Payment::whereIn('invoice_id', $ids)->get() as $v) {
            $rows[] = ['type' => 'payment', 'id' => $v->id, 'date' => $v->paid_at, 'reference' => $v->reference, 'amount_minor' => $v->amount_minor, 'debit_minor' => 0, 'credit_minor' => $v->amount_minor];
        }
        foreach (Refund::whereIn('invoice_id', $ids)->get() as $v) {
            $rows[] = ['type' => 'refund', 'id' => $v->id, 'date' => $v->refunded_at, 'reference' => $v->reason, 'amount_minor' => $v->amount_minor, 'debit_minor' => 0, 'credit_minor' => 0];
        }
        if (class_exists(CreditNote::class)) {
            foreach (CreditNote::whereIn('invoice_id', $ids)->where('status', 'posted')->get() as $v) {
                $rows[] = ['type' => 'credit_note', 'id' => $v->id, 'date' => $v->posted_at, 'reference' => $v->credit_note_number, 'amount_minor' => $v->total_minor, 'debit_minor' => 0, 'credit_minor' => $v->total_minor];
            }
        }
        $balance = 0;
        $rows = collect($rows)->sortBy([['date', 'asc'], ['type', 'asc'], ['id', 'asc']])->values()->map(function (array $row) use (&$balance): array {
            $balance += $row['debit_minor'] - $row['credit_minor'];

            return $row + ['balance_minor' => $balance];
        })->all();

        return response()->json(['data' => ['customer_id' => $id, 'customer_name' => $c->name, 'rows' => $rows, 'closing_balance_minor' => $balance, 'outstanding_minor' => max(0, $balance)]]);
    }
}
