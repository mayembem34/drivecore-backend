<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Rental;
use App\Models\WorkOrder;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use App\Support\InsightQuery;
use App\Support\RollupQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class StatsController extends Controller
{
    public function customers(Request $r): AnonymousResourceCollection
    {
        $q = DirectoryQuery::apply($r, RollupQuery::customers($r), ['name', 'email', 'phone'], ['type' => ['individual', 'corporate']], sortColumns: array_combine(['name', 'type', 'email', 'rental_count', 'total_spent_minor', 'open_ar_minor', 'last_rental_at'], ['name', 'type', 'email', 'rental_count', 'total_spent_minor', 'open_ar_minor', 'last_rental_at']));
        $finance = $r->user()->can('invoices.view');
        $totals = DB::query()->fromSub((clone $q)->reorder(), 'rollups')->selectRaw('COUNT(*) AS total_customers,SUM(total_spent_minor) AS total_spent_minor,SUM(open_ar_minor) AS total_open_ar_minor')->first();
        $active = CompanyAccess::scope(Rental::query(), $r->user())->where('company_id', $r->integer('company_id'))->where('status', 'active')->whereHas('reservation', fn ($x) => $x->whereIn('customer_id', (clone $q)->reorder()->select('customers.id')))->count();

        return RecordResource::collection($q->orderBy('id')->paginate(25)->withQueryString())->additional(['meta' => ['summary' => ['total_customers' => (int) $totals->total_customers, 'active_rentals_count' => $active, 'total_spent_minor' => $finance ? (int) $totals->total_spent_minor : null, 'total_open_ar_minor' => $finance ? (int) $totals->total_open_ar_minor : null]]]);
    }

    public function customerSummary(Request $r, int $id): JsonResponse
    {
        $c = CompanyAccess::record($r->user(), Customer::class, $id);
        Gate::authorize('view', $c);
        $metrics = RollupQuery::customers($r)->findOrFail($id);
        $finance = $r->user()->can('invoices.view');
        $rentals = CompanyAccess::scope(Rental::query(), $r->user())->whereHas('reservation', fn ($q) => $q->where('customer_id', $id))->with(['reservation.customer', 'vehicle', 'driver', 'invoice'])->orderByDesc('id')->limit(5)->get();
        $invoices = $finance ? CompanyAccess::scope(Invoice::query(), $r->user())->where('customer_id', $id)->with(['lines', 'payments', 'refunds', 'creditNotes', 'customer'])->orderByDesc('id')->limit(5)->get() : collect();

        return response()->json(['data' => ['customer_id' => $id] + RollupQuery::customerStats($metrics, $finance) + ['recent_rentals' => RecordResource::collection($rentals)->resolve($r), 'recent_invoices' => RecordResource::collection($invoices)->resolve($r)]]);
    }

    public function vehicles(Request $r): AnonymousResourceCollection
    {
        $r->validate(['min_rate_minor' => ['sometimes', 'integer', 'min:0'], 'max_rate_minor' => ['sometimes', 'integer', 'min:0'], 'functions' => ['sometimes', 'string', 'max:500'], 'idle_days_min' => ['sometimes', 'integer', 'min:0'], 'unpriced_only' => ['sometimes', 'boolean']]);
        $sorts = ['registration', 'make', 'daily_rate_minor', 'odometer', 'status', 'rental_count', 'utilization_pct', 'idle_days', 'revenue_minor', 'net_roi_minor'];
        $q = DirectoryQuery::apply($r, RollupQuery::vehicles($r), ['registration', 'make', 'model'], ['status' => ['available', 'reserved', 'rented', 'maintenance', 'damaged', 'accident', 'awaiting_inspection', 'awaiting_repair', 'sold', 'decommissioned']], sortColumns: array_combine($sorts, $sorts));
        foreach (['min_rate_minor' => '>=', 'max_rate_minor' => '<='] as $field => $op) {
            if ($r->filled($field)) {
                $q->where('daily_rate_minor', $op, $r->integer($field));
            }
        } if ($r->filled('idle_days_min')) {
            $q->where('idle_days', '>=', $r->integer('idle_days_min'));
        } if ($r->boolean('unpriced_only')) {
            $q->where('daily_rate_minor', 0);
        } if ($r->filled('functions')) {
            foreach (array_filter(explode(',', $r->input('functions'))) as $function) {
                $q->whereJsonContains('functions', trim($function));
            }
        }
        $summary = DB::query()->fromSub((clone $q)->reorder(), 'rollups')->selectRaw('COUNT(*) AS total_vehicles,AVG(utilization_pct) AS avg_utilization_pct,SUM(CASE WHEN idle_days>14 THEN 1 ELSE 0 END) AS idle_over_14d_count,SUM(CASE WHEN daily_rate_minor=0 THEN 1 ELSE 0 END) AS unpriced_count,SUM(net_roi_minor) AS total_net_roi_minor')->first();

        return RecordResource::collection($q->orderBy('id')->paginate(25)->withQueryString())->additional(['meta' => ['summary' => ['total_vehicles' => (int) $summary->total_vehicles, 'avg_utilization_pct' => round((float) $summary->avg_utilization_pct, 2), 'idle_over_14d_count' => (int) $summary->idle_over_14d_count, 'unpriced_count' => (int) $summary->unpriced_count, 'total_net_roi_minor' => $r->user()->can('invoices.view') ? (int) $summary->total_net_roi_minor : null]]]);
    }

    public function branches(Request $r): JsonResponse
    {
        $branches = DirectoryQuery::apply($r, CompanyAccess::scope(Branch::query(), $r->user()), [])->orderBy('id')->get();
        $rows = [];
        foreach ($branches as $b) {
            $copy = $r->duplicate(array_replace($r->query(), ['branch_id' => $b->id]));
            $copy->setUserResolver(fn () => $r->user());
            $vehicles = RollupQuery::vehicles($copy)->where('branch_id', $b->id)->get();
            $finance = $r->user()->can('invoices.view');
            $financial = $finance ? $this->branchFinance($copy) : [];
            $orders = InsightQuery::period(WorkOrder::where('branch_id', $b->id), $r, 'created_at')->get();
            $downtime = 0;
            foreach ($orders as $order) {
                if ($order->started_at) {
                    $downtime += max(0, $order->started_at->diffInSeconds($order->completed_at ?? now(), false) / 3600);
                }
            }
            $rows[] = ['branch_id' => $b->id, 'branch_name' => $b->name, 'fleet_total' => $vehicles->count(), 'utilization_pct' => round((float) $vehicles->avg('utilization_pct'), 2), 'rental_count' => (int) $vehicles->sum('rental_count'), 'work_order_count' => $orders->count(), 'downtime_hours' => round($downtime, 2), 'revenue_minor' => $finance ? (int) $vehicles->sum('revenue_minor') : null, 'expenses_minor' => $financial['expenses_minor'] ?? null, 'net_position_minor' => $financial['net_position_minor'] ?? null, 'open_ar_minor' => $financial['outstanding_ar_minor'] ?? null];
        }

        return response()->json(['data' => $rows]);
    }

    private function branchFinance(Request $r): array
    {
        return (new InsightController)->financial($r)->getData(true)['data']['summary'];
    }
}
