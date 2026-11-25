<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\ApprovalRequest;
use App\Models\ApprovalThreshold;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Support\ApprovalRules;
use App\Support\CompanyAccess;
use App\Support\CreditControl;
use App\Support\DirectoryQuery;
use App\Support\RatePricing;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class QuotationController extends Controller
{
    private function resource(Quotation $quotation): RecordResource
    {
        return new RecordResource($quotation->load(['customer', 'vehicles', 'decider']));
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = DirectoryQuery::apply($request, CompanyAccess::scope(Quotation::query(), $request->user()), ['id', 'customer:name', 'vehicles:registration'], ['status' => ['draft', 'pending_approval', 'approved', 'rejected', 'cancelled', 'converted']], integerFilters: ['customer_id'], sortColumns: ['id' => 'id', 'total_minor' => 'total_minor', 'status' => 'status', 'customer_name' => Customer::select('name')->whereColumn('customers.id', 'quotations.customer_id')->limit(1)]);

        return RecordResource::collection($query->with(['customer', 'vehicles', 'decider'])->orderBy('id')->paginate(25)->withQueryString());
    }

    public function show(Request $request, int $quotation): RecordResource
    {
        $record = CompanyAccess::record($request->user(), Quotation::class, $quotation);
        Gate::authorize('view', $record);

        return $this->resource($record);
    }

    public function store(Request $request): RecordResource
    {
        $data = $request->validate(['rate_card_id' => ['nullable', 'integer', 'min:1'], 'company_id' => ['required', 'integer', 'min:1'], 'branch_id' => ['required', 'integer', 'min:1'], 'customer_id' => ['required', 'integer', 'min:1'], 'vehicle_ids' => ['required', 'array', 'min:1', 'max:25'], 'vehicle_ids.*' => ['required', 'integer', 'min:1', 'distinct'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'discount_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'], 'notes' => ['nullable', 'string', 'max:10000']]);

        return DB::transaction(function () use ($request, $data): RecordResource {
            CompanyAccess::branch($request->user(), $data['company_id'], $data['branch_id']);
            $company = CompanyAccess::company($request->user(), $data['company_id']);
            CompanyAccess::scope(Customer::query(), $request->user())->where('company_id', $company->id)->where('branch_id', $data['branch_id'])->findOrFail($data['customer_id']);
            $card = isset($data['rate_card_id']) ? RatePricing::card($request->user(), $data['rate_card_id'], $company->id, $data['branch_id'], $data['customer_id']) : null;
            $vehicles = CompanyAccess::scope(Vehicle::query(), $request->user())->where('company_id', $company->id)->where('branch_id', $data['branch_id'])->whereIn('id', $data['vehicle_ids'])->orderBy('id')->lockForUpdate()->get();
            if ($vehicles->count() !== count($data['vehicle_ids']) || $vehicles->contains(fn ($v) => $v->status !== 'available' || (! $card && $v->daily_rate_minor <= 0) || ($card && $card->vehicle_category !== null && $card->vehicle_category !== $v->category))) {
                throw ValidationException::withMessages(['vehicle_ids' => 'Select available, priced vehicles in this branch.']);
            }
            $start = CarbonImmutable::parse($data['starts_at'])->utc();
            $end = CarbonImmutable::parse($data['ends_at'])->utc();
            abort_if(Reservation::whereIn('vehicle_id', $data['vehicle_ids'])->where('status', 'confirmed')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists(), 409, 'A selected vehicle is already reserved.');
            $days = max(1, (int) ceil($start->diffInSeconds($end) / 86400));
            abort_if($days > 3650, 422, 'Quotations cannot exceed 3650 days.');
            $subtotal = $card ? RatePricing::total($card, $days) * $vehicles->count() : (int) $vehicles->sum(fn ($v) => $v->daily_rate_minor * $days);
            $discountPct = (float) ($data['discount_pct'] ?? 0);
            $discount = (int) round($subtotal * $discountPct / 100);
            $vat = (int) round(($subtotal - $discount) * $company->vat_rate_pct / 100);
            $threshold = ApprovalThreshold::where('company_id', $company->id)->where('type', 'discount')->where('is_active', true)->first();
            $pending = $threshold ? ApprovalRules::needed($company->id, 'discount', $discountPct) : $discountPct > $company->discount_approval_threshold_pct;
            $record = Quotation::create(['rate_card_id' => $card?->id, 'company_id' => $company->id, 'branch_id' => $data['branch_id'], 'customer_id' => $data['customer_id'], 'created_by' => $request->user()->id, 'starts_at' => $start, 'ends_at' => $end, 'rental_days' => $days, 'status' => $pending ? 'pending_approval' : 'approved', 'discount_pct' => $discountPct, 'discount_status' => $pending ? 'pending' : 'not_required', 'subtotal_minor' => $subtotal, 'discount_minor' => $discount, 'vat_rate_pct' => $company->vat_rate_pct, 'vat_minor' => $vat, 'total_minor' => $subtotal - $discount + $vat, 'notes' => $data['notes'] ?? null]);
            foreach ($vehicles as $vehicle) {
                $record->vehicles()->attach($vehicle->id, ['daily_rate_minor' => $card?->daily_rate_minor ?? $vehicle->daily_rate_minor, 'line_subtotal_minor' => $card ? RatePricing::total($card, $days) : $vehicle->daily_rate_minor * $days]);
            }
            if ($pending) {
                ApprovalRequest::create(['company_id' => $record->company_id, 'branch_id' => $record->branch_id, 'type' => 'discount', 'entity_type' => 'quotation', 'entity_id' => $record->id, 'title' => 'Quotation discount', 'requester_id' => $request->user()->id, 'previous_value' => '0', 'requested_value' => (string) $discountPct, 'reason' => 'Quotation discount approval']);
            }
            RecordAudit::write($request->user(), 'quotation.created', $record);

            return $this->resource($record);
        }, 3);
    }

    public function decide(Request $request, int $quotation, string $decision): RecordResource
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);
        $record = CompanyAccess::record($request->user(), Quotation::class, $quotation);
        $approval = ApprovalRequest::where('company_id', $record->company_id)->where('type', 'discount')->where('entity_type', 'quotation')->where('entity_id', $quotation)->where('status', 'pending')->first();
        abort_unless($approval, 409);
        $copy = $request->duplicate();
        $copy->setUserResolver(fn () => $request->user());
        $copy->merge(['decision_notes' => $data['notes'] ?? null]);
        (new ApprovalController)->decide($copy, $approval->id, $decision === 'approve-discount' ? 'approve' : 'reject');

        return $this->resource($record->refresh());
    }

    public function cancel(Request $request, int $quotation): RecordResource
    {
        CompanyAccess::record($request->user(), Quotation::class, $quotation);

        return DB::transaction(function () use ($request, $quotation): RecordResource {
            $record = Quotation::lockForUpdate()->findOrFail($quotation);
            Gate::authorize('update', $record);
            abort_unless(in_array($record->status, ['pending_approval', 'approved'], true), 409);
            $record->update(['status' => 'cancelled']);
            RecordAudit::write($request->user(), 'quotation.cancelled', $record);

            return $this->resource($record);
        }, 3);
    }

    public function convert(Request $request, int $quotation): RecordResource
    {
        CompanyAccess::record($request->user(), Quotation::class, $quotation);

        return DB::transaction(function () use ($request, $quotation): RecordResource {
            $record = Quotation::lockForUpdate()->findOrFail($quotation);
            Gate::authorize('update', $record);
            CreditControl::assertCanBook($record->customer_id);
            abort_unless($record->status === 'approved', 409);
            $ids = $record->vehicles()->pluck('vehicles.id');
            $vehicles = Vehicle::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            foreach ($vehicles as $vehicle) {
                abort_unless($vehicle->status === 'available', 409);
                abort_if(Reservation::where('vehicle_id', $vehicle->id)->where('status', 'confirmed')->where('starts_at', '<', $record->ends_at)->where('ends_at', '>', $record->starts_at)->exists(), 409);
            }
            foreach ($vehicles as $vehicle) {
                $reservation = Reservation::create(['company_id' => $record->company_id, 'branch_id' => $record->branch_id, 'customer_id' => $record->customer_id, 'vehicle_id' => $vehicle->id, 'created_by' => $request->user()->id, 'starts_at' => $record->starts_at, 'ends_at' => $record->ends_at, 'status' => 'confirmed']);
                $record->vehicles()->updateExistingPivot($vehicle->id, ['reservation_id' => $reservation->id]);
                RecordAudit::write($request->user(), 'reservation.created', $reservation);
            }
            $record->update(['status' => 'converted', 'converted_at' => now()]);
            RecordAudit::write($request->user(), 'quotation.converted', $record);

            return $this->resource($record);
        }, 3);
    }
}
