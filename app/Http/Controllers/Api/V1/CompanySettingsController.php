<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Support\CompanyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanySettingsController extends Controller
{
    public function show(Request $request, int $company): JsonResponse
    {
        $record = CompanyAccess::company($request->user(), $company);

        return response()->json(['data' => ['company_id' => $record->id, 'currency' => $record->currency, 'vat_rate_pct' => $record->vat_rate_pct, 'discount_approval_threshold_pct' => $record->discount_approval_threshold_pct]]);
    }

    public function update(Request $request, int $company): JsonResponse
    {
        $record = CompanyAccess::company($request->user(), $company);
        abort_unless($request->user()->is_platform_admin || $record->pivot->branch_id === null, 403);
        $data = $request->validate(['vat_rate_pct' => ['required', 'numeric', 'min:0', 'max:100'], 'discount_approval_threshold_pct' => ['required', 'numeric', 'min:0', 'max:100']]);
        $record->update($data);
        RecordAudit::write($request->user(), 'company.settings_updated', $record);

        return $this->show($request, $company);
    }
}
