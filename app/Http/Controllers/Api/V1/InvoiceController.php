<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PostJournal;
use App\Actions\PriceInvoice;
use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public const PAID = '(SELECT COALESCE(SUM(amount_minor),0) FROM payments WHERE payments.invoice_id=invoices.id)';

    public const CREDIT = "(SELECT COALESCE(SUM(total_minor),0) FROM credit_notes WHERE credit_notes.invoice_id=invoices.id AND credit_notes.status='posted')";

    public const OPEN = "CASE WHEN invoices.status='issued' AND invoices.total_minor-".self::PAID.'-'.self::CREDIT.' > 0 THEN invoices.total_minor-'.self::PAID.'-'.self::CREDIT.' ELSE 0 END';

    private function resource(Invoice $record): RecordResource
    {
        return new RecordResource($record->load(['lines', 'payments', 'refunds', 'creditNotes', 'customer']));
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['sometimes', Rule::in(['draft', 'issued', 'paid', 'overdue', 'void'])], 'ageing_bucket' => ['sometimes', Rule::in(['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'])]]);
        $query = DirectoryQuery::apply($request, CompanyAccess::scope(Invoice::query(), $request->user())->select('invoices.*')->selectRaw(self::OPEN.' AS outstanding_minor'), ['invoice_number', 'custom_customer_name', 'customer:name'], integerFilters: ['customer_id', 'rental_id'], sortColumns: ['id' => 'id', 'customer_name' => Customer::select('name')->whereColumn('customers.id', 'invoices.customer_id')->limit(1), 'due_at' => 'due_at', 'total_minor' => 'total_minor', 'outstanding_minor' => 'outstanding_minor', 'status' => 'status', 'created_at' => 'created_at']);
        if ($request->filled('status')) {
            match ($request->input('status')) {
                'paid' => $query->where('status', 'issued')->whereRaw('('.self::PAID.'+'.self::CREDIT.') >= total_minor'),'overdue' => $query->where('status', 'issued')->where('due_at', '<', now())->whereRaw(self::OPEN.' > 0'),default => $query->where('status', $request->input('status'))
            };
        }
        if ($request->filled('ageing_bucket')) {
            $query->where('status', 'issued')->whereRaw(self::OPEN.' > 0');
            $bucket = $request->input('ageing_bucket');
            if ($bucket === 'current') {
                $query->where('due_at', '>=', now());
            } else {
                [$low,$high] = match ($bucket) {
                    'd1_30' => [0, 30],'d31_60' => [30, 60],'d61_90' => [60, 90],default => [90, null]
                };
                $query->where('due_at', '<', now()->subDays($low));
                if ($high !== null) {
                    $query->where('due_at', '>=', now()->subDays($high));
                }
            }
        }

        return RecordResource::collection($query->with(['lines', 'payments', 'refunds', 'creditNotes', 'customer'])->orderByDesc('id')->paginate(25)->withQueryString());
    }

    public function show(Request $request, int $invoice): RecordResource
    {
        $record = CompanyAccess::record($request->user(), Invoice::class, $invoice);
        Gate::authorize('view', $record);

        return $this->resource($record);
    }

    public function store(Request $request, PriceInvoice $price): RecordResource
    {
        $data = $this->validateInvoice($request, true);

        return DB::transaction(function () use ($request, $data, $price): RecordResource {
            CompanyAccess::branch($request->user(), $data['company_id'], $data['branch_id']);
            if (isset($data['customer_id'])) {
                CompanyAccess::scope(Customer::query(), $request->user())->where('company_id', $data['company_id'])->where('branch_id', $data['branch_id'])->findOrFail($data['customer_id']);
            }
            $company = CompanyAccess::company($request->user(), $data['company_id']);
            $record = new Invoice(collect($data)->except(['lines', 'discount_pct'])->all());
            $record->invoice_number = 'INV-'.Str::ulid();
            $record->created_by = $request->user()->id;
            $record->status = 'draft';
            $price->handle($record, $data['lines'], (float) ($data['discount_pct'] ?? 0), $company->vat_rate_pct);
            RecordAudit::write($request->user(), 'invoice.created', $record);

            return $this->resource($record);
        }, 3);
    }

    private function validateInvoice(Request $request, bool $create): array
    {
        $rules = ['lines' => ['required', 'array', 'min:1', 'max:50'], 'lines.*.description' => ['required', 'string', 'max:255'], 'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'], 'lines.*.unit_price_minor' => ['required', 'integer', 'min:0', 'max:1000000000000'], 'discount_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'], 'due_at' => [$create ? 'required' : 'sometimes', 'date'], 'notes' => ['sometimes', 'nullable', 'string', 'max:10000'], 'custom_customer_name' => ['sometimes', 'nullable', 'string', 'max:255'], 'custom_customer_email' => ['sometimes', 'nullable', 'email', 'max:255'], 'custom_customer_phone' => ['sometimes', 'nullable', 'string', 'max:50']];
        if ($create) {
            $rules += ['company_id' => ['required', 'integer', 'min:1'], 'branch_id' => ['required', 'integer', 'min:1'], 'customer_id' => ['nullable', 'required_without:custom_customer_name', 'prohibits:custom_customer_name,custom_customer_email,custom_customer_phone', 'integer', 'min:1']];
            $rules['custom_customer_name'] = ['nullable', 'required_without:customer_id', 'prohibits:customer_id', 'string', 'max:255'];
        }

        return $request->validate($rules);
    }

    public function update(Request $request, int $invoice, PriceInvoice $price): RecordResource
    {
        $data = $this->validateInvoice($request, false);
        CompanyAccess::record($request->user(), Invoice::class, $invoice);

        return DB::transaction(function () use ($request, $invoice, $data, $price): RecordResource {
            $record = Invoice::lockForUpdate()->findOrFail($invoice);
            Gate::authorize('update', $record);
            abort_unless($record->status === 'draft', 409);
            $fields = collect($data)->except(['lines', 'discount_pct'])->all();
            if ($record->customer_id !== null && array_intersect(array_keys($fields), ['custom_customer_name', 'custom_customer_email', 'custom_customer_phone'])) {
                throw ValidationException::withMessages(['custom_customer_name' => 'CRM invoices cannot use custom customer details.']);
            }if ($record->customer_id === null && array_key_exists('custom_customer_name', $data) && ! trim((string) $data['custom_customer_name'])) {
                throw ValidationException::withMessages(['custom_customer_name' => 'A customer name is required.']);
            }$record->fill($fields);
            $price->handle($record, $data['lines'], (float) ($data['discount_pct'] ?? $record->discount_pct), $record->vat_rate_pct);
            RecordAudit::write($request->user(), 'invoice.updated', $record);

            return $this->resource($record);
        }, 3);
    }

    public function transition(Request $request, int $invoice, string $action, PostJournal $journal): RecordResource
    {
        CompanyAccess::record($request->user(), Invoice::class, $invoice);

        return DB::transaction(function () use ($request, $invoice, $action, $journal): RecordResource {
            $record = Invoice::lockForUpdate()->findOrFail($invoice);
            Gate::authorize('update', $record);
            $net = $record->total_minor - $record->vat_minor;
            if ($action === 'issue') {
                abort_unless($record->status === 'draft', 409);
                if ($record->total_minor <= 0) {
                    throw ValidationException::withMessages(['total_minor' => 'Invoice total must be positive.']);
                }$record->update(['status' => 'issued', 'issued_at' => now()]);
                $journal->handle($request->user(), $record->company_id, $record->branch_id, 'invoice', $record->id, [['1100', $record->total_minor, 0], ['4000', 0, $net], ['2100', 0, $record->vat_minor]], $record->invoice_number, 'Invoice issued');
            } else {
                abort_if($record->status === 'void' || $record->payments()->exists() || $record->creditNotes()->exists(), 409);
                if ($record->status === 'issued') {
                    $journal->handle($request->user(), $record->company_id, $record->branch_id, 'invoice', $record->id, [['4000', $net, 0], ['2100', $record->vat_minor, 0], ['1100', 0, $record->total_minor]], $record->invoice_number, 'Invoice voided');
                }$record->update(['status' => 'void', 'voided_at' => now()]);
            }
            RecordAudit::write($request->user(), 'invoice.'.($action === 'issue' ? 'issued' : 'voided'), $record);

            return $this->resource($record);
        }, 3);
    }

    public function settlement(Request $request, int $invoice, string $kind, PostJournal $journal): RecordResource
    {
        $refund = $kind === 'refunds';
        $data = $request->validate(['approval_request_id' => ['nullable', 'integer', 'min:1'], 'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000000000'], 'method' => [$refund ? 'sometimes' : 'required', Rule::in(['cash', 'card', 'bank_transfer', 'mobile_money'])], 'reference' => [$refund ? 'sometimes' : 'required', 'string', 'max:120'], 'reason' => [$refund ? 'required' : 'sometimes', 'string', 'max:500'], ($refund ? 'refunded_at' : 'paid_at') => ['sometimes', 'date']]);
        CompanyAccess::record($request->user(), Invoice::class, $invoice);

        return DB::transaction(function () use ($request, $invoice, $refund, $data, $journal): RecordResource {
            $record = Invoice::lockForUpdate()->findOrFail($invoice);
            Gate::authorize('view', $record);
            Gate::authorize('payments.manage');
            abort_unless($record->status === 'issued', 409);
            $balance = $record->load(['payments', 'refunds', 'creditNotes'])->balances();
            if ($data['amount_minor'] > $balance[$refund ? 'net_paid_minor' : 'outstanding_minor']) {
                throw ValidationException::withMessages(['amount_minor' => 'Amount exceeds the available balance.']);
            }
            if ($refund) {
                ApprovalRules::check($request->user(), $record, 'refund_writeoff', 'invoice', $data['amount_minor'], $data['approval_request_id'] ?? null);
            }
            $type = $refund ? 'refund' : 'payment';
            $fields = ['company_id' => $record->company_id, 'branch_id' => $record->branch_id, 'invoice_id' => $record->id, 'recorded_by' => $request->user()->id, 'amount_minor' => $data['amount_minor']];
            $fields += $refund ? ['reason' => $data['reason'], 'refunded_at' => $data['refunded_at'] ?? now()] : ['method' => $data['method'], 'reference' => $data['reference'], 'paid_at' => $data['paid_at'] ?? now()];
            $item = $refund ? Refund::create($fields) : Payment::create($fields);
            $amount = $item->amount_minor;
            $journal->handle($request->user(), $record->company_id, $record->branch_id, $type, $item->id, $refund ? [['4100', $amount, 0], ['1000', 0, $amount]] : [['1000', $amount, 0], ['1100', 0, $amount]], $data['reference'] ?? $record->invoice_number, ucfirst($type).' recorded');
            RecordAudit::write($request->user(), $type.'.recorded', $item);

            return new RecordResource($item);
        }, 3);
    }

    public function ageing(Request $request): JsonResponse
    {
        $query = DirectoryQuery::apply($request, CompanyAccess::scope(Invoice::query(), $request->user()), [])->where('status', 'issued')->whereRaw(self::OPEN.' > 0')->with(['payments', 'refunds', 'customer']);
        $keys = ['current' => 'Current', 'd1_30' => '1–30d', 'd31_60' => '31–60d', 'd61_90' => '61–90d', 'd90_plus' => '90+d'];
        $buckets = [];
        foreach ($keys as $key => $label) {
            $buckets[$key] = ['key' => $key, 'label' => $label, 'count' => 0, 'amount_minor' => 0];
        }$exposures = [];
        $total = 0;
        foreach ($query->lazyById(100) as $record) {
            $amount = $record->balances()['outstanding_minor'];
            $age = $record->due_at->diffInDays(now(), false);
            $key = $age <= 0 ? 'current' : ($age <= 30 ? 'd1_30' : ($age <= 60 ? 'd31_60' : ($age <= 90 ? 'd61_90' : 'd90_plus')));
            $buckets[$key]['count']++;
            $buckets[$key]['amount_minor'] += $amount;
            $total += $amount;
            $group = $record->customer_id !== null ? 'crm:'.$record->customer_id : 'custom:'.$record->custom_customer_name;
            $exposures[$group] ??= ['customer_id' => $record->customer_id, 'customer_name' => $record->customer?->name ?? $record->custom_customer_name, 'customer_type' => $record->customer?->type ?? 'custom', 'outstanding_minor' => 0, 'overdue_count' => 0];
            $exposures[$group]['outstanding_minor'] += $amount;
            $exposures[$group]['overdue_count'] += (int) ($age > 0);
        }
        $exposures = collect($exposures)->sortByDesc('outstanding_minor')->take(20)->values()->all();

        return response()->json(['data' => ['buckets' => array_values($buckets), 'total_outstanding_minor' => $total, 'exposures' => $exposures]]);
    }
}
