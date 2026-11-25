<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Support\CompanyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanySettingsController extends Controller
{
    public function show(Request $request, int $company): JsonResponse
    {
        $record = CompanyAccess::company($request->user(), $company);

        return response()->json(['data' => ['company_id' => $record->id, 'currency' => $record->currency, 'require_2fa_for_privileged' => (bool) $record->require_2fa_for_privileged, 'require_inspection_photos' => (bool) $record->require_inspection_photos, 'distance_unit' => $record->distance_unit, 'volume_unit' => $record->volume_unit, 'fuel_efficiency_unit' => $record->fuel_efficiency_unit, 'weight_unit' => $record->weight_unit, 'vat_rate_pct' => $record->vat_rate_pct, 'discount_approval_threshold_pct' => $record->discount_approval_threshold_pct]]);
    }

    public function update(Request $request, int $company): JsonResponse
    {
        $record = CompanyAccess::company($request->user(), $company);
        abort_if($request->has('require_2fa_for_privileged') && ! $request->user()->is_platform_admin && ! $request->user()->hasRole('system-administrator'), 403);
        abort_unless($request->user()->is_platform_admin || $record->pivot->branch_id === null, 403);
        $data = $request->validate(['require_2fa_for_privileged' => ['sometimes', 'boolean'], 'require_inspection_photos' => ['sometimes', 'boolean'], 'distance_unit' => ['sometimes', Rule::in(['km', 'mi'])], 'volume_unit' => ['sometimes', Rule::in(['l', 'gal_us'])], 'fuel_efficiency_unit' => ['sometimes', Rule::in(['l_per_100km', 'km_per_l', 'mpg_us', 'mpg_uk'])], 'weight_unit' => ['sometimes', Rule::in(['kg', 'lb'])], 'vat_rate_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'], 'discount_approval_threshold_pct' => ['sometimes', 'numeric', 'min:0', 'max:100']]);
        $record->update($data);
        RecordAudit::write($request->user(), 'company.settings_updated', $record);

        return $this->show($request, $company);
    }
}
