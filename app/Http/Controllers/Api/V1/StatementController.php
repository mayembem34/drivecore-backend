<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\RentalAmendment;
use App\Models\SupplierInvoice;
use App\Support\InsightQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatementController extends Controller
{
    public function show(Request $r, string $statement): JsonResponse
    {
        if ($statement === 'vat-return') {
            $output = (int) InsightQuery::period(InsightQuery::workspace($r, Invoice::class)->where('status', 'issued'), $r, 'issued_at')->sum('vat_minor') - (int) InsightQuery::period(InsightQuery::workspace($r, CreditNote::class)->where('status', 'posted'), $r, 'posted_at')->sum('vat_minor');
            foreach (InsightQuery::period(InsightQuery::workspace($r, RentalAmendment::class)->where('type', 'deposit_retained'), $r, 'created_at')->lazyById(100) as $event) {
                $output += (int) ($event->changes['vat_minor'] ?? 0);
            }
            $input = (int) InsightQuery::period(InsightQuery::workspace($r, SupplierInvoice::class)->whereNotIn('status', ['draft', 'void']), $r, 'invoice_date')->sum('vat_minor');

            return response()->json(['data' => ['output_vat_minor' => $output, 'input_vat_minor' => $input, 'net_vat_minor' => $output - $input]]);
        }
        $entries = InsightQuery::workspace($r, JournalEntry::class);
        if ($statement === 'balance-sheet') {
            if ($r->filled('to')) {
                $entries->where('posted_at', '<=', $r->date('to')->endOfDay());
            }
        } else {
            InsightQuery::period($entries, $r, 'posted_at');
        }
        $rows = JournalLine::query()->join('gl_accounts', 'gl_accounts.id', '=', 'journal_lines.gl_account_id')->whereIn('journal_entry_id', $entries->select('journal_entries.id'))->select(['gl_accounts.code', 'gl_accounts.name', 'gl_accounts.type'])->selectRaw('SUM(debit_minor) AS debit_minor,SUM(credit_minor) AS credit_minor')->groupBy('gl_accounts.code', 'gl_accounts.name', 'gl_accounts.type')->orderBy('gl_accounts.code')->get();
        $accounts = $rows->map(fn ($row) => ['code' => $row->code, 'name' => $row->name, 'type' => $row->type, 'debit_minor' => (int) $row->debit_minor, 'credit_minor' => (int) $row->credit_minor, 'balance_minor' => (int) $row->debit_minor - (int) $row->credit_minor])->all();
        $sum = function (string $type) use ($accounts): int {
            return (int) collect($accounts)->where('type', $type)->sum('balance_minor');
        };
        $profit = -$sum('revenue') - $sum('expense');
        $totals = match ($statement) {
            'pnl' => ['revenue_minor' => -$sum('revenue'), 'expenses_minor' => $sum('expense'), 'net_profit_minor' => $profit], 'balance-sheet' => ['assets_minor' => $sum('asset'), 'liabilities_minor' => -$sum('liability'), 'equity_minor' => -$sum('equity'), 'retained_profit_minor' => $profit, 'liabilities_and_equity_minor' => -$sum('liability') - $sum('equity') + $profit, 'balance_difference_minor' => $sum('asset') + $sum('liability') + $sum('equity') - $profit],default => ['cash_inflows_minor' => (int) collect($accounts)->whereIn('code', ['1000', '1010', '1020'])->sum('debit_minor'), 'cash_outflows_minor' => (int) collect($accounts)->whereIn('code', ['1000', '1010', '1020'])->sum('credit_minor'), 'net_cash_flow_minor' => (int) collect($accounts)->whereIn('code', ['1000', '1010', '1020'])->sum('balance_minor')]
        };

        return response()->json(['data' => ['statement' => $statement, 'summary' => $totals, 'rows' => $accounts]]);
    }
}
