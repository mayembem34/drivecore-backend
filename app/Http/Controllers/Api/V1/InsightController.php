<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CollectionNote;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Part;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Refund;
use App\Models\Rental;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use App\Support\InsightQuery;
use App\Support\RollupQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InsightController extends Controller
{
    private function query(Request $request, string $model, ?string $date = null): Builder
    {
        $q = InsightQuery::workspace($request, $model);

        return $date ? InsightQuery::period($q, $request, $date) : $q;
    }

    private function financialData(Request $request): array
    {
        $invoices = $this->query($request, Invoice::class, 'issued_at')->where('status', 'issued');
        $invoiced = (int) (clone $invoices)->sum('total_minor');
        $vat = (int) (clone $invoices)->sum('vat_minor');
        $receipts = (int) $this->query($request, Payment::class, 'paid_at')->sum('amount_minor');
        $refunds = (int) $this->query($request, Refund::class, 'refunded_at')->sum('amount_minor');
        $expenses = (int) $this->query($request, Expense::class, 'incurred_at')->sum('amount_minor');
        $ar = (int) $this->query($request, Invoice::class)->where('status', 'issued')->selectRaw('COALESCE(SUM('.InvoiceController::OPEN.'),0) AS aggregate')->value('aggregate');

        return ['invoiced_minor' => $invoiced, 'vat_minor' => $vat, 'receipts_minor' => $receipts, 'refunds_minor' => $refunds, 'expenses_minor' => $expenses, 'outstanding_ar_minor' => $ar, 'net_position_minor' => $receipts - $refunds - $expenses];
    }

    public function dashboard(Request $request): JsonResponse
    {
        $driver = $request->user()->hasRole('driver') && ! $request->user()->can('vehicles.view');
        if ($driver) {
            $rentals = $this->query($request, Rental::class)->where('driver_user_id', $request->user()->id);

            return response()->json(['data' => ['fleet' => null, 'pipeline' => null, 'approvals' => null, 'finance' => null, 'driver_summary' => ['active_rentals' => (clone $rentals)->where('status', 'active')->count(), 'upcoming_rentals' => (clone $rentals)->where('status', 'draft')->whereHas('reservation', fn (Builder $q) => $q->where('starts_at', '>=', now()))->count()]]]);
        }
        Gate::authorize('vehicles.view');
        $vehicles = $this->query($request, Vehicle::class);
        $fleet = ['total' => (clone $vehicles)->count()];
        foreach (['available', 'rented', 'maintenance', 'damaged'] as $status) {
            $fleet[$status] = (clone $vehicles)->where('status', $status)->count();
        } $fleet['utilization_pct'] = $fleet['total'] ? round($fleet['rented'] / $fleet['total'] * 100, 2) : 0;
        $pipeline = ['quotations' => $this->query($request, Quotation::class, 'created_at')->count(), 'reservations' => $this->query($request, Reservation::class, 'starts_at')->where('status', 'confirmed')->count(), 'active_rentals' => $this->query($request, Rental::class)->where('status', 'active')->count(), 'issued_invoices' => $this->query($request, Invoice::class, 'issued_at')->where('status', 'issued')->count()];
        $approvals = ['pending_discount_quotes' => $this->query($request, Quotation::class)->where('status', 'pending_approval')->count(), 'pending_purchase_orders' => $this->query($request, PurchaseOrder::class)->where('status', 'submitted')->count(), 'low_stock_parts' => $this->query($request, Part::class)->whereRaw('(SELECT COALESCE(SUM(quantity),0) FROM stock_movements WHERE stock_movements.part_id=parts.id) <= parts.min_stock')->count(), 'overdue_invoices' => $this->query($request, Invoice::class)->where('status', 'issued')->where('due_at', '<', now())->whereRaw(InvoiceController::OPEN.' > 0')->count()];
        $finance = null;
        if ($request->user()->can('invoices.view')) {
            $f = $this->financialData($request);
            $finance = ['revenue_minor' => $f['invoiced_minor'] - $f['refunds_minor'], 'vat_minor' => $f['vat_minor'], 'receipts_minor' => $f['receipts_minor'], 'expenses_minor' => $f['expenses_minor'], 'outstanding_ar_minor' => $f['outstanding_ar_minor'], 'net_position_minor' => $f['net_position_minor']];
            $pipeline['issued_invoices'] = $this->query($request, Invoice::class, 'issued_at')->where('status', 'issued')->count();
            $approvals['overdue_invoices'] = $this->query($request, Invoice::class)->where('status', 'issued')->where('due_at', '<', now())->whereRaw(InvoiceController::OPEN.' > 0')->count();
        }

        return response()->json(['data' => ['fleet' => $fleet, 'pipeline' => $pipeline, 'approvals' => $approvals, 'finance' => $finance, 'driver_summary' => null] + $this->extras($request)]);
    }

    private function extras(Request $r): array
    {
        $r->validate(['include' => ['sometimes', 'string', 'max:100']]);
        $includes = array_filter(explode(',', $r->input('include', '')));
        abort_if(count(array_diff($includes, ['credit', 'compliance', 'operations_alerts', 'vehicle_profitability'])) > 0, 422, 'Unknown dashboard include.');
        $extra = [];
        if (in_array('credit', $includes, true)) {
            $extra['credit'] = null;
            if ($r->user()->can('credit.view') || $r->user()->can('customers.view')) {
                $extra['credit'] = ['held_customers' => $this->query($r, Customer::class)->where('is_on_credit_hold', true)->count(), 'overdue_promises' => $r->user()->can('credit.view') ? $this->query($r, CollectionNote::class)->where('note_type', 'promise_to_pay')->where('status', 'open')->whereDate('promised_date', '<', now()->toDateString())->count() : null];
            }
        }
        if (in_array('compliance', $includes, true)) {
            $extra['compliance'] = null;
            if ($r->user()->can('documents.view')) {
                $docs = $this->query($r, Document::class);
                $extra['compliance'] = ['expired' => (clone $docs)->where('expires_at', '<', now()->startOfDay())->count(), 'due_in_30_days' => (clone $docs)->whereBetween('expires_at', [now()->startOfDay(), now()->addDays(30)->endOfDay()])->count()];
            }
        }
        if (in_array('operations_alerts', $includes, true)) {
            $extra['operations_alerts'] = ['overdue_returns' => $this->query($r, Rental::class)->where('status', 'active')->whereHas('reservation', fn (Builder $q) => $q->where('ends_at', '<', now()))->count(), 'restricted_vehicles' => $this->query($r, Vehicle::class)->whereIn('status', ['accident', 'damaged', 'maintenance', 'awaiting_inspection', 'awaiting_repair'])->count()];
        }
        if (in_array('vehicle_profitability', $includes, true)) {
            $extra['vehicle_profitability'] = null;
            if ($r->user()->can('invoices.view')) {
                $q = DirectoryQuery::apply($r, RollupQuery::vehicles($r), []);
                $extra['vehicle_profitability'] = $q->orderByDesc('net_roi_minor')->orderBy('id')->limit(5)->get()->map(fn (Vehicle $v) => ['vehicle_id' => $v->id, 'registration' => $v->registration] + RollupQuery::vehicleStats($v, true))->all();
            }
        }

        return $extra;
    }

    public function financial(Request $request): JsonResponse
    {
        $summary = $this->financialData($request);
        $rows = [];
        foreach ($this->query($request, Invoice::class, 'issued_at')->where('status', 'issued')->with(['payments', 'refunds', 'creditNotes'])->lazyById(100) as $invoice) {
            $rows[] = ['type' => 'invoice', 'id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'total_minor' => $invoice->total_minor, 'vat_minor' => $invoice->vat_minor] + $invoice->balances();
        }
        foreach ($this->query($request, Expense::class, 'incurred_at')->lazyById(100) as $expense) {
            $rows[] = ['type' => 'expense', 'id' => $expense->id, 'category' => $expense->category, 'amount_minor' => $expense->amount_minor, 'incurred_at' => $expense->incurred_at];
        }

        return response()->json(['data' => ['summary' => $summary, 'rows' => $rows]]);
    }

    public function fleet(Request $request): JsonResponse
    {
        $rows = [];
        $finance = $request->user()->can('invoices.view');
        foreach ($this->query($request, Vehicle::class)->lazyById(100) as $vehicle) {
            $rentals = InsightQuery::period(Rental::where('vehicle_id', $vehicle->id), $request, 'handed_over_at');
            $orders = InsightQuery::period(WorkOrder::where('vehicle_id', $vehicle->id), $request, 'created_at');
            $downtime = 0;
            foreach (WorkOrder::where('vehicle_id', $vehicle->id)->whereNotNull('started_at')->lazyById(100) as $order) {
                $start = $order->started_at;
                $end = $order->completed_at ?? now();
                if ($request->filled('from')) {
                    $start = $start->max($request->date('from')->startOfDay());
                } if ($request->filled('to')) {
                    $end = $end->min($request->date('to')->endOfDay());
                } $downtime += max(0, $start->diffInSeconds($end, false) / 3600);
            }
            $revenue = null;
            $maintenance = null;
            if ($finance) {
                $revenue = 0;
                foreach (InsightQuery::period(Invoice::where('status', 'issued')->whereIn('rental_id', Rental::where('vehicle_id', $vehicle->id)->select('id')), $request, 'issued_at')->with('refunds')->lazyById(100) as $invoice) {
                    $revenue += $invoice->total_minor - (int) $invoice->refunds->sum('amount_minor');
                } $maintenance = (int) (clone $orders)->where('status', 'completed')->selectRaw('COALESCE(SUM(labour_cost_minor+parts_cost_minor),0) AS aggregate')->value('aggregate');
            }
            $rows[] = ['vehicle_id' => $vehicle->id, 'registration' => $vehicle->registration, 'make_model' => trim($vehicle->make.' '.$vehicle->model), 'status' => $vehicle->status, 'rental_count' => $rentals->count(), 'work_order_count' => $orders->count(), 'downtime_hours' => round($downtime, 2), 'revenue_minor' => $revenue, 'maintenance_cost_minor' => $maintenance, 'net_roi_minor' => $finance ? $revenue - $maintenance : null];
        }

        return response()->json(['data' => ['rows' => $rows]]);
    }

    public function audit(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1'], 'action' => ['sometimes', 'string', 'max:100'], 'entity_type' => ['sometimes', 'string', 'max:100']]);
        $company = CompanyAccess::company($request->user(), $data['company_id']);
        $q = AuditEvent::where('company_id', $company->id)->select('audit_events.*')->addSelect(['actor_name' => User::select('name')->whereColumn('users.id', 'audit_events.user_id')->limit(1)]);
        if (! $request->user()->is_platform_admin && $company->pivot->branch_id !== null) {
            $q->where('branch_id', $company->pivot->branch_id);
        }
        if (isset($data['action'])) {
            $q->where('action', $data['action']);
        } if (isset($data['entity_type'])) {
            $q->where('subject_type', $data['entity_type']);
        }
        $q = DirectoryQuery::apply($request, $q, ['action', 'subject_type'], workspace: false);
        $page = $q->orderByDesc('id')->paginate(25)->withQueryString();
        $page->through(fn (AuditEvent $e) => ['id' => $e->id, 'actor_name' => $e->actor_name, 'action' => $e->action, 'entity_type' => $e->subject_type, 'entity_id' => $e->subject_id, 'summary' => $e->action.' '.$e->subject_type.' #'.$e->subject_id, 'created_at' => $e->created_at]);

        return response()->json($page);
    }
}
