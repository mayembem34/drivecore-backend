<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PostJournal;
use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\ApprovalRequest;
use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use App\Models\Rental;
use App\Models\Vehicle;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FixedAssetController extends Controller
{
    public function index(Request $r): AnonymousResourceCollection
    {
        return RecordResource::collection(DirectoryQuery::apply($r, CompanyAccess::scope(FixedAsset::query(), $r->user()), ['asset_code', 'name'], ['status' => ['active', 'pending_disposal', 'disposed']], integerFilters: ['vehicle_id'])->orderByDesc('id')->paginate(25)->withQueryString());
    }

    public function store(Request $r, PostJournal $journal): RecordResource
    {
        $d = $r->validate(['company_id' => ['required', 'integer', 'min:1'], 'branch_id' => ['required', 'integer', 'min:1'], 'vehicle_id' => ['nullable', 'integer', 'min:1'], 'asset_code' => ['required', 'string', 'max:40'], 'name' => ['required', 'string', 'max:140'], 'category' => ['sometimes', Rule::in(['vehicle', 'workshop_equipment', 'office_equipment', 'other'])], 'acquisition_cost_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'], 'residual_value_minor' => ['sometimes', 'integer', 'min:0', 'lte:acquisition_cost_minor'], 'capitalisation_date' => ['required', 'date'], 'useful_life_months' => ['required', 'integer', 'between:1,1200'], 'depreciation_method' => ['sometimes', Rule::in(['straight_line', 'reducing_balance'])]]);
        CompanyAccess::branch($r->user(), $d['company_id'], $d['branch_id']);

        return DB::transaction(function () use ($r, $d, $journal): RecordResource {
            if (isset($d['vehicle_id'])) {
                $v = CompanyAccess::scope(Vehicle::query(), $r->user())->where('company_id', $d['company_id'])->where('branch_id', $d['branch_id'])->lockForUpdate()->findOrFail($d['vehicle_id']);
                abort_if(FixedAsset::where('vehicle_id', $v->id)->exists(), 409, 'Vehicle already has an asset record.');
            } if (FixedAsset::where('company_id', $d['company_id'])->where('asset_code', $d['asset_code'])->exists()) {
                throw ValidationException::withMessages(['asset_code' => 'Asset code is already in use.']);
            } $asset = FixedAsset::create($d);
            $journal->handle($r->user(), $asset->company_id, $asset->branch_id, 'fixed_asset', $asset->id, [['1500', $asset->acquisition_cost_minor, 0], ['1000', 0, $asset->acquisition_cost_minor]], $asset->asset_code, 'Asset acquisition');
            RecordAudit::write($r->user(), 'fixed_asset.created', $asset);

            return new RecordResource($asset->refresh());
        }, 3);
    }

    public function depreciate(Request $r, PostJournal $journal): JsonResponse
    {
        $d = $r->validate(['company_id' => ['required', 'integer', 'min:1'], 'branch_id' => ['sometimes', 'integer', 'min:1'], 'period_month' => ['required', 'date_format:Y-m']]);
        $q = DirectoryQuery::apply($r, CompanyAccess::scope(FixedAsset::query(), $r->user()), []);
        $period = CarbonImmutable::createFromFormat('!Y-m', $d['period_month']);
        abort_if($period->startOfMonth()->isFuture(), 422, 'Future depreciation periods are not permitted.');
        $posted = 0;
        $skipped = 0;
        $total = 0;
        foreach ($q->orderBy('id')->lazyById(100) as $visible) {
            $amount = DB::transaction(function () use ($visible, $period, $d, $r, $journal): ?int {
                $a = FixedAsset::lockForUpdate()->findOrFail($visible->id);
                if ($a->status === 'disposed' || $a->capitalisation_date->startOfMonth()->gt($period) || $a->netBookValue() <= $a->residual_value_minor || DepreciationEntry::where('fixed_asset_id', $a->id)->where('period_month', $d['period_month'])->exists()) {
                    return null;
                } $amount = min($a->netBookValue() - $a->residual_value_minor, max(1, (int) round($a->depreciation_method === 'reducing_balance' ? $a->netBookValue() * 2 / $a->useful_life_months : ($a->acquisition_cost_minor - $a->residual_value_minor) / $a->useful_life_months)));
                $entry = DepreciationEntry::create(['company_id' => $a->company_id, 'fixed_asset_id' => $a->id, 'period_month' => $d['period_month'], 'amount_minor' => $amount, 'posted_at' => now()]);
                $gl = $journal->handle($r->user(), $a->company_id, $a->branch_id, 'depreciation', $entry->id, [['5500', $amount, 0], ['1590', 0, $amount]], $a->asset_code.' '.$d['period_month'], 'Monthly depreciation');
                $entry->update(['journal_entry_id' => $gl->id]);
                $a->increment('accumulated_depreciation_minor', $amount);
                RecordAudit::write($r->user(), 'fixed_asset.depreciated', $a);

                return $amount;
            }, 3);
            if ($amount === null) {
                $skipped++;
            } else {
                $posted++;
                $total += $amount;
            }
        }

        return response()->json(['data' => ['period_month' => $d['period_month'], 'posted_count' => $posted, 'skipped_count' => $skipped, 'total_depreciation_minor' => $total]]);
    }

    public function requestDisposal(Request $r, int $id): RecordResource
    {
        $d = $r->validate(['proceeds_minor' => ['required', 'integer', 'min:0', 'max:1000000000000'], 'disposal_type' => ['required', Rule::in(['sold', 'decommissioned'])], 'reason' => ['required', 'string', 'max:10000']]);
        $visible = CompanyAccess::record($r->user(), FixedAsset::class, $id);

        return DB::transaction(function () use ($r, $visible, $d): RecordResource {
            if ($visible->vehicle_id) {
                $v = Vehicle::whereKey($visible->vehicle_id)->lockForUpdate()->firstOrFail();
                abort_if($v->status === 'rented' || Rental::where('vehicle_id', $v->id)->where('status', 'active')->exists(), 409);
            } $a = FixedAsset::lockForUpdate()->findOrFail($visible->id);
            abort_unless($a->status === 'active', 409);
            $approval = ApprovalRequest::create(['company_id' => $a->company_id, 'branch_id' => $a->branch_id, 'type' => 'vehicle_disposal', 'entity_type' => 'fixed_asset', 'entity_id' => $a->id, 'title' => 'Dispose '.$a->asset_code, 'requester_id' => $r->user()->id, 'previous_value' => (string) $a->netBookValue(), 'requested_value' => (string) $d['proceeds_minor'], 'reason' => $d['reason']]);
            $a->update(['status' => 'pending_disposal', 'disposal_approval_id' => $approval->id, 'disposal_type' => $d['disposal_type'], 'disposal_proceeds_minor' => $d['proceeds_minor']]);
            RecordAudit::write($r->user(), 'fixed_asset.disposal_requested', $a);

            return new RecordResource($a);
        }, 3);
    }

    public function dispose(Request $r, int $id, PostJournal $journal): RecordResource
    {
        $r->validate(['disposal_date' => ['sometimes', 'date']]);
        $visible = CompanyAccess::record($r->user(), FixedAsset::class, $id);

        return DB::transaction(function () use ($r, $visible, $journal): RecordResource {
            $v = $visible->vehicle_id ? Vehicle::whereKey($visible->vehicle_id)->lockForUpdate()->firstOrFail() : null;
            abort_if($v && ($v->status === 'rented' || Rental::where('vehicle_id', $v->id)->where('status', 'active')->exists()), 409);
            $a = FixedAsset::lockForUpdate()->findOrFail($visible->id);
            abort_unless($a->status === 'pending_disposal', 409);
            $approval = ApprovalRequest::lockForUpdate()->findOrFail($a->disposal_approval_id);
            abort_unless($approval->status === 'approved' && $approval->type === 'vehicle_disposal' && $approval->entity_type === 'fixed_asset' && $approval->entity_id === $a->id && $approval->requester_id !== $approval->approver_id, 409);
            $proceeds = (int) $approval->approved_value;
            abort_if($proceeds < 0 || ! ctype_digit($approval->approved_value), 422);
            $gain = $proceeds - $a->netBookValue();
            $lines = [['1000', $proceeds, 0], ['1590', $a->accumulated_depreciation_minor, 0], ['1500', 0, $a->acquisition_cost_minor], [$gain >= 0 ? '4900' : '5900', $gain < 0 ? -$gain : 0, $gain > 0 ? $gain : 0]];
            $journal->handle($r->user(), $a->company_id, $a->branch_id, 'asset_disposal', $a->id, $lines, $a->asset_code, 'Asset disposal');
            $a->update(['status' => 'disposed', 'disposal_date' => $r->input('disposal_date', now()->toDateString()), 'disposal_proceeds_minor' => $proceeds, 'disposal_gain_loss_minor' => $gain]);
            $v?->update(['status' => $a->disposal_type]);
            RecordAudit::write($r->user(), 'fixed_asset.disposed', $a);

            return new RecordResource($a);
        }, 3);
    }
}
