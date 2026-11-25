<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PostJournal;
use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BankingController extends Controller
{
    public function accounts(Request $r): AnonymousResourceCollection
    {
        return RecordResource::collection(DirectoryQuery::apply($r, CompanyAccess::scope(BankAccount::query(), $r->user()), ['name', 'bank_name'])->orderBy('id')->get());
    }

    public function storeAccount(Request $r, PostJournal $journal): RecordResource
    {
        $d = $r->validate(['company_id' => ['required', 'integer', 'min:1'], 'branch_id' => ['nullable', Rule::requiredIf($r->integer('opening_balance_minor') !== 0), 'integer', 'min:1'], 'name' => ['required', 'string', 'max:120'], 'bank_name' => ['required', 'string', 'max:120'], 'account_number_masked' => ['required', 'string', 'max:32'], 'currency' => ['sometimes', 'string', 'size:3'], 'gl_account_code' => ['sometimes', Rule::in(['1000', '1010', '1020'])], 'opening_balance_minor' => ['sometimes', 'integer', 'between:-1000000000000,1000000000000'], 'is_active' => ['sometimes', 'boolean']]);
        $company = CompanyAccess::company($r->user(), $d['company_id']);
        if (isset($d['branch_id'])) {
            CompanyAccess::branch($r->user(), $d['company_id'], $d['branch_id']);
        } else {
            abort_unless($r->user()->is_platform_admin || $company->pivot->branch_id === null, 403);
        }

        return DB::transaction(function () use ($r, $d, $journal): RecordResource {
            $a = BankAccount::create($d)->refresh();
            if ($a->opening_balance_minor) {
                $amount = abs($a->opening_balance_minor);
                $journal->handle($r->user(), $a->company_id, $a->branch_id, 'bank_opening', $a->id, $a->opening_balance_minor > 0 ? [[$a->gl_account_code, $amount, 0], ['3000', 0, $amount]] : [['3000', $amount, 0], [$a->gl_account_code, 0, $amount]], $a->name, 'Bank opening balance');
            } RecordAudit::write($r->user(), 'bank_account.created', $a);

            return new RecordResource($a);
        }, 3);
    }

    public function transactions(Request $r): AnonymousResourceCollection
    {
        $r->validate(['bank_account_id' => ['required', 'integer', 'min:1']]);
        $account = CompanyAccess::record($r->user(), BankAccount::class, $r->integer('bank_account_id'));
        $q = BankTransaction::where('bank_account_id', $account->id);
        $r->validate(['match_status' => ['sometimes', Rule::in(['unmatched', 'matched', 'adjusted'])]]);
        if ($r->filled('match_status')) {
            $q->where('match_status', $r->input('match_status'));
        }

        return RecordResource::collection($q->orderByDesc('transaction_date')->orderByDesc('id')->paginate(25)->withQueryString());
    }

    public function import(Request $r, int $id): JsonResponse
    {
        $a = CompanyAccess::record($r->user(), BankAccount::class, $id);
        $r->validate(['transactions' => ['required_without:file', 'array', 'min:1', 'max:1000'], 'file' => ['required_without:transactions', 'file', 'mimes:csv,txt', 'max:2048']]);
        $rows = $r->input('transactions');
        if ($r->hasFile('file')) {
            $handle = fopen($r->file('file')->getRealPath(), 'r');
            try {
                $header = fgetcsv($handle, 0, ',', '"', '');
                abort_unless($header === ['transaction_date', 'description', 'reference', 'amount_minor'], 422, 'CSV columns must be transaction_date,description,reference,amount_minor.');
                $rows = [];
                while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                    abort_if(count($rows) >= 1000 || count($values) !== 4, 422, 'Invalid CSV row or too many rows.');
                    $rows[] = array_combine($header, $values);
                }
            } finally {
                fclose($handle);
            }
        }
        $d = Validator::make(['transactions' => $rows], ['transactions' => ['required', 'array', 'min:1', 'max:1000'], 'transactions.*.transaction_date' => ['required', 'date_format:Y-m-d'], 'transactions.*.description' => ['required', 'string', 'max:200'], 'transactions.*.reference' => ['nullable', 'string', 'max:80'], 'transactions.*.amount_minor' => ['required', 'integer', 'not_in:0', 'between:-1000000000000,1000000000000']])->validate();

        return DB::transaction(function () use ($r, $a, $d): JsonResponse {
            $a = BankAccount::lockForUpdate()->findOrFail($a->id);
            abort_unless($a->is_active, 409);
            $latest = BankReconciliation::where('bank_account_id', $a->id)->where('status', 'closed')->max('statement_date');
            $ids = [];
            foreach ($d['transactions'] as $row) {
                abort_if($latest && $row['transaction_date'] <= $latest, 409, 'This statement period is closed.');
                $ids[] = BankTransaction::create(collect($row)->only(['transaction_date', 'description', 'reference', 'amount_minor'])->all() + ['company_id' => $a->company_id, 'bank_account_id' => $a->id])->id;
            } RecordAudit::write($r->user(), 'bank_account.imported', $a);

            return response()->json(['data' => ['imported_count' => count($ids), 'transaction_ids' => $ids]], 201);
        }, 3);
    }

    private function target(Request $r, BankAccount $account, string $type, int $id, bool $lock = false): Payment|SupplierPayment|Expense
    {
        $class = match ($type) {
            'payment' => Payment::class,'supplier_payment' => SupplierPayment::class,'expense' => Expense::class
        };
        $q = $class::where('company_id', $account->company_id);
        if ($account->branch_id !== null) {
            if ($class === SupplierPayment::class) {
                $q->whereIn('supplier_invoice_id', SupplierInvoice::where('branch_id', $account->branch_id)->select('id'));
            } else {
                $q->where('branch_id', $account->branch_id);
            }
        } $target = ($lock ? $q->lockForUpdate() : $q)->findOrFail($id);
        if ($target instanceof SupplierPayment) {
            abort_if($target->bank_account_id !== null && $target->bank_account_id !== $account->id, 404);
        }

        return $target;
    }

    public function suggestions(Request $r, int $id): JsonResponse
    {
        $t = BankTransaction::findOrFail($id);
        $a = CompanyAccess::record($r->user(), BankAccount::class, $t->bank_account_id);
        $rows = [];
        foreach ($t->amount_minor > 0 ? ['payment' => Payment::class] : ['supplier_payment' => SupplierPayment::class, 'expense' => Expense::class] as $type => $class) {
            $q = $class::where('company_id', $a->company_id)->where('amount_minor', abs($t->amount_minor))->whereNotIn('id', BankTransaction::where('company_id', $a->company_id)->where('matched_entity_type', $type)->whereNotNull('matched_entity_id')->select('matched_entity_id'));
            if ($a->branch_id !== null) {
                if ($class === SupplierPayment::class) {
                    $q->whereIn('supplier_invoice_id', SupplierInvoice::where('branch_id', $a->branch_id)->select('id'));
                } else {
                    $q->where('branch_id', $a->branch_id);
                }
            } foreach ($q->orderBy('id')->limit(20)->get() as $record) {
                $rows[] = ['entity_type' => $type, 'entity_id' => $record->id, 'amount_minor' => $record->amount_minor, 'reference' => $record->reference ?? $record->description, 'reference_matches' => $t->reference !== null && ($record->reference ?? null) === $t->reference];
            }
        }

        return response()->json(['data' => $rows]);
    }

    public function match(Request $r, int $id): RecordResource
    {
        $d = $r->validate(['entity_type' => ['required', Rule::in(['payment', 'supplier_payment', 'expense'])], 'entity_id' => ['required', 'integer', 'min:1']]);
        $visible = BankTransaction::findOrFail($id);
        $account = CompanyAccess::record($r->user(), BankAccount::class, $visible->bank_account_id);

        return DB::transaction(function () use ($r, $id, $d, $account): RecordResource {
            BankAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $t = BankTransaction::lockForUpdate()->findOrFail($id);
            abort_unless($t->match_status === 'unmatched' && $t->reconciliation_id === null, 409);
            $target = $this->target($r, $account, $d['entity_type'], $d['entity_id'], true);
            abort_if(BankTransaction::where('company_id', $account->company_id)->where('matched_entity_type', $d['entity_type'])->where('matched_entity_id', $target->id)->exists(), 409, 'This payment or expense is already matched.');
            if (($d['entity_type'] === 'payment') !== ($t->amount_minor > 0) || abs($t->amount_minor) !== $target->amount_minor) {
                throw ValidationException::withMessages(['entity_id' => 'The signed amount must match exactly.']);
            } $t->update(['match_status' => 'matched', 'matched_entity_type' => $d['entity_type'], 'matched_entity_id' => $target->id]);
            RecordAudit::write($r->user(), 'bank_transaction.matched', $t, $t->company_id);

            return new RecordResource($t);
        }, 3);
    }

    public function autoMatch(Request $r, int $id): JsonResponse
    {
        $a = CompanyAccess::record($r->user(), BankAccount::class, $id);
        $matched = 0;
        foreach (BankTransaction::where('bank_account_id', $a->id)->where('match_status', 'unmatched')->orderBy('id')->lazyById(100) as $t) {
            $suggestions = $this->suggestions($r, $t->id)->getData(true)['data'];
            $exact = array_values(array_filter($suggestions, fn (array $s) => $s['reference_matches']));
            if (count($exact) === 1) {
                $copy = $r->duplicate();
                $copy->setUserResolver(fn () => $r->user());
                $copy->merge($exact[0]);
                $this->match($copy, $t->id);
                $matched++;
            }
        }

        return response()->json(['data' => ['matched_count' => $matched]]);
    }

    public function unmatch(Request $r, int $id): RecordResource
    {
        $t = BankTransaction::findOrFail($id);
        $a = CompanyAccess::record($r->user(), BankAccount::class, $t->bank_account_id);

        return DB::transaction(function () use ($r, $id, $a): RecordResource {
            BankAccount::whereKey($a->id)->lockForUpdate()->firstOrFail();
            $t = BankTransaction::lockForUpdate()->findOrFail($id);
            abort_if($t->reconciliation_id !== null || $t->match_status === 'adjusted', 409);
            $t->update(['match_status' => 'unmatched', 'matched_entity_type' => null, 'matched_entity_id' => null]);
            RecordAudit::write($r->user(), 'bank_transaction.unmatched', $t);

            return new RecordResource($t);
        }, 3);
    }

    public function fee(Request $r, int $id, PostJournal $journal): RecordResource
    {
        $t = BankTransaction::findOrFail($id);
        $a = CompanyAccess::record($r->user(), BankAccount::class, $t->bank_account_id);

        return DB::transaction(function () use ($r, $id, $a, $journal): RecordResource {
            BankAccount::whereKey($a->id)->lockForUpdate()->firstOrFail();
            $t = BankTransaction::lockForUpdate()->findOrFail($id);
            abort_unless($a->branch_id !== null && $t->amount_minor < 0 && $t->match_status === 'unmatched' && $t->reconciliation_id === null, 409);
            $e = Expense::create(['company_id' => $a->company_id, 'branch_id' => $a->branch_id, 'recorded_by' => $r->user()->id, 'category' => 'bank_charges', 'description' => $t->description, 'amount_minor' => -$t->amount_minor, 'incurred_at' => $t->transaction_date]);
            $journal->handle($r->user(), $a->company_id, $a->branch_id, 'bank_fee', $t->id, [['5600', $e->amount_minor, 0], [$a->gl_account_code, 0, $e->amount_minor]], $t->reference ?? 'BANK-'.$t->id, 'Bank charge');
            $t->update(['match_status' => 'adjusted', 'matched_entity_type' => 'expense', 'matched_entity_id' => $e->id]);
            RecordAudit::write($r->user(), 'bank_transaction.fee_posted', $t);

            return new RecordResource($t);
        }, 3);
    }

    public function close(Request $r, int $id): RecordResource
    {
        $d = $r->validate(['statement_date' => ['required', 'date_format:Y-m-d'], 'statement_closing_balance_minor' => ['required', 'integer', 'between:-1000000000000000,1000000000000000']]);
        $a = CompanyAccess::record($r->user(), BankAccount::class, $id);

        return DB::transaction(function () use ($r, $a, $d): RecordResource {
            $a = BankAccount::lockForUpdate()->findOrFail($a->id);
            abort_if(BankReconciliation::where('bank_account_id', $a->id)->where('status', 'closed')->whereDate('statement_date', '>=', $d['statement_date'])->exists(), 409);
            $q = BankTransaction::where('bank_account_id', $a->id)->whereDate('transaction_date', '<=', $d['statement_date']);
            $balance = $a->opening_balance_minor + (int) (clone $q)->sum('amount_minor');
            if ($balance !== (int) $d['statement_closing_balance_minor'] || (clone $q)->where('match_status', 'unmatched')->exists()) {
                throw ValidationException::withMessages(['statement_closing_balance_minor' => 'Balance must agree and every transaction must be matched or adjusted.']);
            } $record = BankReconciliation::create(['company_id' => $a->company_id, 'bank_account_id' => $a->id] + $d + ['reconciled_balance_minor' => $balance, 'status' => 'closed', 'closed_at' => now()]);
            $q->whereNull('reconciliation_id')->update(['reconciliation_id' => $record->id]);
            $a->update(['last_reconciled_at' => now()]);
            RecordAudit::write($r->user(), 'bank_reconciliation.closed', $record);

            return new RecordResource($record);
        }, 3);
    }
}
