<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PostJournal;
use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\Expense;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Vehicle;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FinanceController extends Controller
{
    public function index(Request $request, string $domain): AnonymousResourceCollection
    {
        [$model,$search,$filters,$integers,$sort] = match ($domain) {
            'payments' => [Payment::class, ['reference'], ['method' => ['cash', 'card', 'bank_transfer', 'mobile_money']], ['invoice_id'], ['id', 'paid_at', 'amount_minor', 'method']],
            'refunds' => [Refund::class, ['reason'], [], ['invoice_id'], ['id', 'refunded_at', 'amount_minor']],
            'expenses' => [Expense::class, ['description'], ['category' => ['maintenance', 'fuel', 'insurance', 'tolls_parking', 'general']], ['vehicle_id'], ['id', 'incurred_at', 'description', 'category', 'amount_minor']]
        };

        return RecordResource::collection(DirectoryQuery::apply($request, CompanyAccess::scope($model::query(), $request->user()), $search, $filters, integerFilters: $integers, sortColumns: array_combine($sort, $sort))->orderByDesc('id')->paginate(25)->withQueryString());
    }

    public function storeExpense(Request $request, PostJournal $journal): RecordResource
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1'], 'branch_id' => ['required', 'integer', 'min:1'], 'vehicle_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'category' => ['required', Rule::in(['maintenance', 'fuel', 'insurance', 'tolls_parking', 'general'])], 'description' => ['required', 'string', 'max:255'], 'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000000000'], 'incurred_at' => ['required', 'date']]);

        return DB::transaction(function () use ($request, $data, $journal): RecordResource {
            CompanyAccess::branch($request->user(), $data['company_id'], $data['branch_id']);
            if (isset($data['vehicle_id'])) {
                CompanyAccess::scope(Vehicle::query(), $request->user())->where('company_id', $data['company_id'])->where('branch_id', $data['branch_id'])->findOrFail($data['vehicle_id']);
            }$record = Expense::create([...$data, 'recorded_by' => $request->user()->id]);
            $journal->handle($request->user(), $record->company_id, $record->branch_id, 'expense', $record->id, [['5000', $record->amount_minor, 0], ['1000', 0, $record->amount_minor]], 'EXP-'.$record->id, $record->description);
            RecordAudit::write($request->user(), 'expense.recorded', $record);

            return new RecordResource($record);
        }, 3);
    }

    public function accounts(Request $request): JsonResponse
    {
        $query = DirectoryQuery::apply($request, CompanyAccess::scope(JournalEntry::query(), $request->user()), []);
        $companyId = (int) $request->input('company_id');
        $totals = DB::table('journal_lines')->whereIn('journal_entry_id', $query->select('id'))->selectRaw('gl_account_id,SUM(debit_minor) AS debit_minor,SUM(credit_minor) AS credit_minor')->groupBy('gl_account_id')->get()->keyBy('gl_account_id');
        $accounts = GlAccount::where('company_id', $companyId)->orderBy('code')->get()->map(function ($account) use ($totals): array {
            $debit = (int) ($totals[$account->id]->debit_minor ?? 0);
            $credit = (int) ($totals[$account->id]->credit_minor ?? 0);

            return [...$account->toArray(), 'debit_minor' => $debit, 'credit_minor' => $credit, 'balance_minor' => $debit - $credit];
        });
        $debits = $accounts->sum('debit_minor');
        $credits = $accounts->sum('credit_minor');

        return response()->json(['data' => $accounts, 'total_debits_minor' => $debits, 'total_credits_minor' => $credits, 'is_balanced' => $debits === $credits]);
    }

    public function journals(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['account_code' => ['sometimes', 'string', 'max:20']]);
        $query = DirectoryQuery::apply($request, CompanyAccess::scope(JournalEntry::query(), $request->user()), ['reference', 'memo'], ['source_type' => ['invoice', 'payment', 'refund', 'expense', 'work_order', 'purchase_order', 'stock_adjustment']]);
        if (isset($data['account_code'])) {
            $query->whereHas('lines.account', fn ($q) => $q->where('code', $data['account_code']));
        }

        return RecordResource::collection($query->with('lines.account')->orderByDesc('posted_at')->orderByDesc('id')->paginate(25)->withQueryString());
    }
}
