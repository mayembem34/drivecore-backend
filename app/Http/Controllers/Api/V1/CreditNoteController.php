<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PostJournal;
use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\ApprovalRequest;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Support\ApprovalRules;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreditNoteController extends Controller
{
    public function index(Request $r): AnonymousResourceCollection
    {
        return RecordResource::collection(DirectoryQuery::apply($r, CompanyAccess::scope(CreditNote::query(), $r->user()), ['credit_note_number', 'reason'], integerFilters: ['invoice_id', 'customer_id'])->orderByDesc('id')->paginate(25)->withQueryString());
    }

    public function store(Request $r, int $id, PostJournal $journal): RecordResource
    {
        abort_unless($r->user()->can('invoices.manage') || $r->user()->can('credit_notes.request'), 403);
        $d = $r->validate(['total_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'], 'reason' => ['required', 'string', 'max:10000']]);
        CompanyAccess::record($r->user(), Invoice::class, $id);

        return DB::transaction(function () use ($r, $id, $d, $journal): RecordResource {
            $invoice = Invoice::lockForUpdate()->findOrFail($id);
            abort_unless($invoice->status === 'issued' && $invoice->customer_id !== null, 409);
            $credited = (int) CreditNote::where('invoice_id', $id)->whereIn('status', ['posted', 'pending_approval'])->sum('total_minor');
            if ($d['total_minor'] > $invoice->total_minor - $credited) {
                throw ValidationException::withMessages(['total_minor' => 'Credit exceeds the remaining creditable amount.']);
            } $vat = (int) round($d['total_minor'] * $invoice->vat_minor / max(1, $invoice->total_minor));
            $pending = ! $r->user()->can('invoices.manage') || ApprovalRules::needed($invoice->company_id, 'refund_writeoff', $d['total_minor']);
            $note = CreditNote::create(['company_id' => $invoice->company_id, 'branch_id' => $invoice->branch_id, 'invoice_id' => $id, 'customer_id' => $invoice->customer_id, 'credit_note_number' => 'CN-'.Str::ulid(), 'subtotal_minor' => $d['total_minor'] - $vat, 'vat_minor' => $vat, 'total_minor' => $d['total_minor'], 'reason' => $d['reason'], 'status' => $pending ? 'pending_approval' : 'posted']);
            if ($pending) {
                $approval = ApprovalRequest::create(['company_id' => $invoice->company_id, 'branch_id' => $invoice->branch_id, 'type' => 'refund_writeoff', 'entity_type' => 'credit_note', 'entity_id' => $note->id, 'title' => 'Credit note '.$note->credit_note_number, 'requester_id' => $r->user()->id, 'requested_value' => (string) $note->total_minor, 'reason' => $note->reason]);
                $note->update(['approval_request_id' => $approval->id]);
            } else {
                $this->post($r, $note, $journal);
            } RecordAudit::write($r->user(), 'credit_note.created', $note);

            return new RecordResource($note->refresh());
        }, 3);
    }

    public function publish(Request $r, int $id, PostJournal $journal): RecordResource
    {
        CompanyAccess::record($r->user(), CreditNote::class, $id);

        return DB::transaction(function () use ($r, $id, $journal): RecordResource {
            $visible = CreditNote::findOrFail($id);
            Invoice::whereKey($visible->invoice_id)->lockForUpdate()->firstOrFail();
            $note = CreditNote::lockForUpdate()->findOrFail($id);
            abort_unless($note->status === 'pending_approval', 409);
            $a = ApprovalRequest::lockForUpdate()->findOrFail($note->approval_request_id);
            abort_unless($a->status === 'approved' && $a->type === 'refund_writeoff' && $a->entity_type === 'credit_note' && $a->entity_id === $note->id && $a->requester_id !== $a->approver_id && (int) $a->approved_value === $note->total_minor, 409);
            $this->post($r, $note, $journal);
            RecordAudit::write($r->user(), 'credit_note.posted', $note);

            return new RecordResource($note);
        }, 3);
    }

    private function post(Request $r, CreditNote $note, PostJournal $journal): void
    {
        $entry = $journal->handle($r->user(), $note->company_id, $note->branch_id, 'credit_note', $note->id, [['4000', $note->subtotal_minor, 0], ['2100', $note->vat_minor, 0], ['1100', 0, $note->total_minor]], $note->credit_note_number, $note->reason);
        $note->update(['status' => 'posted', 'journal_entry_id' => $entry->id, 'posted_at' => now()]);
    }
}
