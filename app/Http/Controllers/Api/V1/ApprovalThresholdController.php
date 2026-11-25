<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Models\ApprovalThreshold;
use App\Models\Company;
use App\Support\CompanyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ApprovalThresholdController extends Controller
{
    public function show(Request $r, int $id): JsonResponse
    {
        CompanyAccess::company($r->user(), $id);

        return response()->json(['data' => ApprovalThreshold::where('company_id', $id)->orderBy('type')->get()]);
    }

    public function update(Request $r, int $id): JsonResponse
    {
        $c = CompanyAccess::company($r->user(), $id);
        abort_unless($r->user()->is_platform_admin || $c->pivot->branch_id === null, 403);
        $d = $r->validate(['thresholds' => ['required', 'array', 'min:1', 'max:7'], 'thresholds.*.type' => ['required', 'distinct', Rule::in(ApprovalController::TYPES)], 'thresholds.*.threshold_minor' => ['nullable', 'integer', 'between:0,1000000000000'], 'thresholds.*.threshold_pct' => ['nullable', 'numeric', 'between:0,100'], 'thresholds.*.approver_role' => ['required', Rule::in(['ceo', 'general-manager', 'finance-manager', 'system-administrator'])], 'thresholds.*.is_active' => ['sometimes', 'boolean']]);

        return DB::transaction(function () use ($r, $id, $c, $d): JsonResponse {
            Company::whereKey($id)->lockForUpdate()->firstOrFail();
            foreach ($d['thresholds'] as $row) {
                ApprovalThreshold::updateOrCreate(['company_id' => $id, 'type' => $row['type']], $row);
            } RecordAudit::write($r->user(), 'company.approval_thresholds_updated', $c);

            return $this->show($r, $id);
        }, 3);
    }
}
