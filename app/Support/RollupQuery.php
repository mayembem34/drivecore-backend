<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\InvoiceController;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Rental;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class RollupQuery
{
    public const MONEY_SORTS = ['total_spent_minor', 'open_ar_minor', 'revenue_minor', 'net_roi_minor'];

    public static function validate(Request $r): void
    {
        $r->validate(['include_stats' => ['sometimes', 'boolean'], 'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from']]);
        if (! $r->user()->can('invoices.view')) {
            abort_if($r->hasAny(['min_spend_minor', 'max_spend_minor', 'has_open_ar']) || in_array($r->input('sort'), self::MONEY_SORTS, true), 403);
        }
    }

    private static function period(Builder $q, Request $r, string $date): Builder
    {
        return InsightQuery::period($q, $r, $date);
    }

    public static function customers(Request $r): Builder
    {
        self::validate($r);
        $q = CompanyAccess::scope(Customer::query(), $r->user())->select('customers.*');
        $rentals = self::period(Rental::query()->join('reservations', 'reservations.id', '=', 'rentals.reservation_id')->whereColumn('reservations.customer_id', 'customers.id'), $r, 'rentals.handed_over_at');
        $q->selectSub((clone $rentals)->selectRaw('COUNT(*)'), 'rental_count')->selectSub((clone $rentals)->selectRaw('MAX(handed_over_at)'), 'last_rental_at');
        if ($r->user()->can('invoices.view')) {
            $paid = self::period(Payment::query()->join('invoices', 'invoices.id', '=', 'payments.invoice_id')->whereColumn('invoices.customer_id', 'customers.id'), $r, 'paid_at')->selectRaw('COALESCE(SUM(payments.amount_minor),0)');
            $refund = self::period(Refund::query()->join('invoices', 'invoices.id', '=', 'refunds.invoice_id')->whereColumn('invoices.customer_id', 'customers.id'), $r, 'refunded_at')->selectRaw('COALESCE(SUM(refunds.amount_minor),0)');
            $invoiced = self::period(Invoice::whereColumn('invoices.customer_id', 'customers.id')->where('status', 'issued'), $r, 'issued_at')->selectRaw('COALESCE(SUM(total_minor),0)');
            $ar = Invoice::whereColumn('invoices.customer_id', 'customers.id')->selectRaw('COALESCE(SUM('.InvoiceController::OPEN.'),0)');
            $q->selectSub($paid, 'payments_minor')->selectSub($refund, 'refunds_minor')->selectSub($invoiced, 'total_invoiced_minor')->selectSub($ar, 'open_ar_minor');
        } else {
            $q->selectRaw('CAST(NULL AS BIGINT) AS payments_minor,CAST(NULL AS BIGINT) AS refunds_minor,CAST(NULL AS BIGINT) AS total_invoiced_minor,CAST(NULL AS BIGINT) AS open_ar_minor');
        }
        $outer = Customer::query()->fromSub($q, 'customers')->select('customers.*');
        $spent = 'CASE WHEN payments_minor-refunds_minor>0 THEN payments_minor-refunds_minor ELSE 0 END';
        $outer->selectRaw($r->user()->can('invoices.view') ? $spent.' AS total_spent_minor' : 'CAST(NULL AS BIGINT) AS total_spent_minor');
        $r->validate(['min_spend_minor' => ['sometimes', 'integer', 'min:0'], 'max_spend_minor' => ['sometimes', 'integer', 'min:0'], 'has_open_ar' => ['sometimes', 'boolean']]);
        foreach (['min_spend_minor' => '>=', 'max_spend_minor' => '<='] as $field => $op) {
            if ($r->filled($field)) {
                $outer->whereRaw($spent.' '.$op.' ?', [$r->integer($field)]);
            }
        }
        if ($r->has('has_open_ar')) {
            $outer->where('open_ar_minor', $r->boolean('has_open_ar') ? '>' : '=', 0);
        }

        return Customer::query()->fromSub($outer, 'customers')->select('customers.*');
    }

    public static function customerStats(Customer $c, bool $finance): array
    {
        $count = (int) $c->rental_count;

        return ['rental_count' => $count, 'last_rental_at' => $c->last_rental_at, 'total_spent_minor' => $finance ? (int) $c->total_spent_minor : null, 'total_invoiced_minor' => $finance ? (int) $c->total_invoiced_minor : null, 'open_ar_minor' => $finance ? (int) $c->open_ar_minor : null, 'avg_rental_value_minor' => $finance ? ($count ? (int) round($c->total_invoiced_minor / $count) : 0) : null];
    }

    public static function vehicles(Request $r): Builder
    {
        self::validate($r);
        $q = CompanyAccess::scope(Vehicle::query(), $r->user())->select('vehicles.*');
        $rental = self::period(Rental::whereColumn('rentals.vehicle_id', 'vehicles.id'), $r, 'handed_over_at');
        $q->selectSub((clone $rental)->selectRaw('COUNT(*)'), 'rental_count')->selectSub((clone $rental)->selectRaw('COALESCE(SUM(CASE WHEN return_odometer>=handover_odometer THEN return_odometer-handover_odometer ELSE 0 END),0)'), 'total_km_driven');
        $start = $r->filled('from') ? $r->date('from')->startOfDay() : now()->subDays(29)->startOfDay();
        $end = $r->filled('to') ? $r->date('to')->endOfDay()->min(now()) : now();
        $seconds = max(1, $start->diffInSeconds($end, false));
        $driver = $q->getConnection()->getDriverName();
        $delta = $driver === 'pgsql' ? 'EXTRACT(EPOCH FROM (LEAST(COALESCE(returned_at, CAST(? AS TIMESTAMP)),CAST(? AS TIMESTAMP))-GREATEST(handed_over_at,CAST(? AS TIMESTAMP))))' : '(julianday(MIN(COALESCE(returned_at,?),?))-julianday(MAX(handed_over_at,?)))*86400';
        $use = Rental::whereColumn('rentals.vehicle_id', 'vehicles.id')->whereNotNull('handed_over_at')->where('handed_over_at', '<', $end)->where(fn (Builder $x) => $x->whereNull('returned_at')->orWhere('returned_at', '>', $start))->selectRaw('COALESCE(SUM('.$delta.'),0)*100.0 / ?', [$end, $end, $start, $seconds]);
        $q->selectSub($use, 'utilization_pct');
        $last = Rental::whereColumn('rentals.vehicle_id', 'vehicles.id')->selectRaw('MAX(COALESCE(returned_at,handed_over_at))');
        $q->selectSub($last, 'last_used_at');
        if ($r->user()->can('invoices.view')) {
            $invoices = Invoice::where('status', 'issued')->whereIn('rental_id', Rental::whereColumn('rentals.vehicle_id', 'vehicles.id')->select('id'));
            $q->selectSub(self::period(clone $invoices, $r, 'issued_at')->selectRaw('COALESCE(SUM(total_minor),0)'), 'invoiced_minor')->selectSub(self::period(Refund::whereIn('invoice_id', (clone $invoices)->select('invoices.id')), $r, 'refunded_at')->selectRaw('COALESCE(SUM(amount_minor),0)'), 'refunded_minor')->selectSub(self::period(CreditNote::where('status', 'posted')->whereIn('invoice_id', (clone $invoices)->select('invoices.id')), $r, 'posted_at')->selectRaw('COALESCE(SUM(total_minor),0)'), 'credited_minor')->selectSub(self::period(Expense::whereColumn('expenses.vehicle_id', 'vehicles.id'), $r, 'incurred_at')->selectRaw('COALESCE(SUM(amount_minor),0)'), 'expense_minor');
        } else {
            $q->selectRaw('CAST(NULL AS BIGINT) AS invoiced_minor,CAST(NULL AS BIGINT) AS refunded_minor,CAST(NULL AS BIGINT) AS credited_minor,CAST(NULL AS BIGINT) AS expense_minor');
        }
        $out = Vehicle::query()->fromSub($q, 'vehicles')->select('vehicles.*');
        $idle = $driver === 'pgsql' ? 'GREATEST(0,FLOOR(EXTRACT(EPOCH FROM (CAST(? AS TIMESTAMP)-COALESCE(last_used_at,created_at)))/86400))' : 'MAX(0,CAST(julianday(?)-julianday(COALESCE(last_used_at,created_at)) AS INTEGER))';
        $out->selectRaw($idle.' AS idle_days', [now()])->selectRaw('invoiced_minor-refunded_minor-credited_minor AS revenue_minor,invoiced_minor-refunded_minor-credited_minor-expense_minor AS net_roi_minor');

        return Vehicle::query()->fromSub($out, 'vehicles')->select('vehicles.*')->with('fixedAsset');
    }

    public static function vehicleStats(Vehicle $v, bool $finance): array
    {
        return ['rental_count' => (int) $v->rental_count, 'utilization_pct' => min(100, round((float) $v->utilization_pct, 2)), 'total_km_driven' => (int) $v->total_km_driven, 'idle_days' => (int) $v->idle_days, 'revenue_minor' => $finance ? (int) $v->revenue_minor : null, 'expense_minor' => $finance ? (int) $v->expense_minor : null, 'net_roi_minor' => $finance ? (int) $v->net_roi_minor : null];
    }
}
