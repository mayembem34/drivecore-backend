<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PostJournal;
use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\Company;
use App\Models\Quotation;
use App\Models\Rental;
use App\Models\RentalAmendment;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Support\ApprovalRules;
use App\Support\CompanyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContractController extends Controller
{
    public function accept(Request $r, int $id): RecordResource
    {
        $d = $r->validate(['customer_acceptance_ref' => ['required', 'string', 'max:80']]);
        CompanyAccess::record($r->user(), Quotation::class, $id);

        return DB::transaction(function () use ($r, $id, $d): RecordResource {
            $q = Quotation::lockForUpdate()->findOrFail($id);
            abort_unless($q->status === 'approved' && $q->customer_accepted_at === null, 409);
            $q->update(['customer_accepted_at' => now(), 'customer_acceptance_ref' => $d['customer_acceptance_ref']]);
            RecordAudit::write($r->user(), 'quotation.accepted', $q);

            return new RecordResource($q->load(['customer', 'vehicles', 'decider']));
        }, 3);
    }

    public function amend(Request $r, int $id): RecordResource
    {
        $d = $r->validate(['type' => ['required', Rule::in(['early_return', 'rate_change', 'terms_variation'])], 'ends_at' => ['required_if:type,early_return', 'date'], 'daily_rate_minor' => ['required_if:type,rate_change', 'integer', 'between:1,1000000000000'], 'terms' => ['required_if:type,terms_variation', 'string', 'max:10000'], 'reason' => ['required', 'string', 'max:10000'], 'approval_request_id' => ['nullable', 'integer', 'min:1']]);
        $visible = CompanyAccess::record($r->user(), Rental::class, $id);

        return DB::transaction(function () use ($r, $visible, $d): RecordResource {
            Vehicle::whereKey($visible->vehicle_id)->lockForUpdate()->firstOrFail();
            $rental = Rental::lockForUpdate()->findOrFail($visible->id);
            $reservation = Reservation::lockForUpdate()->findOrFail($rental->reservation_id);
            abort_unless(in_array($rental->status, ['draft', 'active'], true), 409);
            $approvalValue = match ($d['type']) {
                'rate_change' => $d['daily_rate_minor'],'early_return' => $d['ends_at'],default => strlen($d['terms']) > 120 ? 'sha256:'.hash('sha256', $d['terms']) : $d['terms']
            };
            ApprovalRules::check($r->user(), $rental, 'contract_amendment', 'rental', $approvalValue, $d['approval_request_id'] ?? null);
            $changes = [];
            if ($d['type'] === 'early_return') {
                $end = CarbonImmutable::parse($d['ends_at'])->utc();
                if (! $end->lt($reservation->ends_at) || ! $end->gt($reservation->starts_at) || $end->lt(now())) {
                    throw ValidationException::withMessages(['ends_at' => 'Early return must be after the start, no earlier than now, and before the current end.']);
                } $changes = ['previous_ends_at' => $reservation->ends_at->toISOString(), 'ends_at' => $end->toISOString()];
                $reservation->update(['ends_at' => $end]);
            } elseif ($d['type'] === 'rate_change') {
                $changes = ['previous_daily_rate_minor' => $rental->amended_daily_rate_minor, 'daily_rate_minor' => $d['daily_rate_minor']];
                $rental->update(['amended_daily_rate_minor' => $d['daily_rate_minor']]);
            } else {
                $changes = ['previous_terms' => $rental->terms, 'terms' => $d['terms']];
                $rental->update(['terms' => $d['terms']]);
            } RentalAmendment::create(['company_id' => $rental->company_id, 'branch_id' => $rental->branch_id, 'rental_id' => $rental->id, 'created_by' => $r->user()->id, 'type' => $d['type'], 'changes' => $changes, 'reason' => $d['reason']]);
            RecordAudit::write($r->user(), 'rental.amended', $rental, metadata: $changes);

            return new RecordResource($rental->load(['reservation.customer', 'vehicle', 'driver', 'invoice']));
        }, 3);
    }

    public function deposit(Request $r, int $id, PostJournal $journal): RecordResource
    {
        $d = $r->validate(['status' => ['required', Rule::in(['held', 'refunded', 'retained'])], 'amount_minor' => ['required_if:status,held', 'integer', 'between:1,1000000000000'], 'reason' => ['required', 'string', 'max:1000']]);
        CompanyAccess::record($r->user(), Rental::class, $id);

        return DB::transaction(function () use ($r, $id, $d, $journal): RecordResource {
            $rental = Rental::lockForUpdate()->findOrFail($id);
            $amount = $d['status'] === 'held' ? $d['amount_minor'] : $rental->security_deposit_minor;
            abort_unless(($d['status'] === 'held' && $rental->deposit_status === 'none') || ($d['status'] !== 'held' && $rental->deposit_status === 'held'), 409);
            $vat = 0;
            if ($d['status'] === 'held') {
                $lines = [['1000', $amount, 0], ['2200', 0, $amount]];
            } elseif ($d['status'] === 'refunded') {
                $lines = [['2200', $amount, 0], ['1000', 0, $amount]];
            } else {
                $pct = Company::findOrFail($rental->company_id)->vat_rate_pct;
                $vat = (int) round($amount * $pct / (100 + $pct));
                $lines = [['2200', $amount, 0], ['4000', 0, $amount - $vat], ['2100', 0, $vat]];
            } $event = RentalAmendment::create(['company_id' => $rental->company_id, 'branch_id' => $rental->branch_id, 'rental_id' => $rental->id, 'created_by' => $r->user()->id, 'type' => 'deposit_'.$d['status'], 'changes' => ['amount_minor' => $amount, 'vat_minor' => $vat], 'reason' => $d['reason']]);
            $journal->handle($r->user(), $rental->company_id, $rental->branch_id, 'rental_deposit', $event->id, $lines, 'DEPOSIT-'.$rental->id, $d['reason']);
            $rental->update(['security_deposit_minor' => $amount, 'deposit_status' => $d['status']]);
            RecordAudit::write($r->user(), 'rental.deposit_'.$d['status'], $rental);

            return new RecordResource($rental->load(['reservation.customer', 'vehicle', 'driver', 'invoice']));
        }, 3);
    }
}
