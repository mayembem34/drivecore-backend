<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Rental;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Support\CompanyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VehicleSummaryController extends Controller
{
    public function __invoke(Request $request, int $vehicle): JsonResponse
    {
        $record = CompanyAccess::record($request->user(), Vehicle::class, $vehicle);
        Gate::authorize('view', $record);
        $orders = WorkOrder::where('vehicle_id', $record->id);
        $data = ['vehicle_id' => $record->id, 'work_order_count' => (clone $orders)->count(), 'open_work_order_count' => (clone $orders)->whereIn('status', ['open', 'in_progress'])->count(), 'total_downtime_hours' => 0, 'recent_work_orders' => RecordResource::collection((clone $orders)->with(['vehicle', 'movements'])->orderByDesc('id')->limit(5)->get())->resolve($request), 'cumulative_revenue_minor' => null, 'maintenance_cost_minor' => null, 'other_expenses_minor' => null, 'net_roi_minor' => null];
        foreach ((clone $orders)->whereIn('status', ['in_progress', 'completed'])->whereNotNull('started_at')->lazyById(100) as $order) {
            $data['total_downtime_hours'] += max(0, $order->started_at->diffInSeconds($order->completed_at ?? now()) / 3600);
        }
        if ($request->user()->can('invoices.view')) {
            $revenue = 0;
            foreach (Invoice::where('company_id', $record->company_id)->where('status', 'issued')->whereIn('rental_id', Rental::where('vehicle_id', $record->id)->select('id'))->with(['refunds', 'creditNotes'])->lazyById(100) as $invoice) {
                $revenue += $invoice->total_minor - (int) $invoice->refunds->sum('amount_minor') - (int) $invoice->creditNotes->where('status', 'posted')->sum('total_minor');
            }$maintenance = (int) (clone $orders)->where('status', 'completed')->selectRaw('COALESCE(SUM(labour_cost_minor + parts_cost_minor),0) AS aggregate')->value('aggregate');
            $other = (int) Expense::where('vehicle_id', $record->id)->whereNotIn('id', WorkOrder::whereNotNull('expense_id')->select('expense_id'))->sum('amount_minor');
            $data['cumulative_revenue_minor'] = $revenue;
            $data['maintenance_cost_minor'] = $maintenance;
            $data['other_expenses_minor'] = $other;
            $data['net_roi_minor'] = $revenue - $maintenance - $other;
        }

        return response()->json(['data' => $data]);
    }
}
