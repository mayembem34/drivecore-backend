<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\Company;
use App\Models\SavedFilter;
use App\Support\CompanyAccess;
use App\Support\RollupQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SavedFilterController extends Controller
{
    private const MODULES = ['customers', 'vehicles', 'stats_customers', 'stats_vehicles', 'stats_branches'];

    private function sharedAdmin(Request $r): bool
    {
        return $r->user()->is_platform_admin || $r->user()->hasRole('company-admin');
    }

    private function authorizeModule(Request $r, string $module): void
    {
        abort_unless($r->user()->can(match ($module) {
            'customers','stats_customers' => 'customers.view','vehicles','stats_vehicles' => 'vehicles.view',default => 'reports.view'
        }), 403);
    }

    public function index(Request $r): AnonymousResourceCollection
    {
        $d = $r->validate(['company_id' => ['required', 'integer', 'min:1'], 'module' => ['required', Rule::in(self::MODULES)]]);
        CompanyAccess::company($r->user(), $d['company_id']);
        $this->authorizeModule($r, $d['module']);
        $q = SavedFilter::where('company_id', $d['company_id'])->where('module', $d['module'])->where(fn ($q) => $q->where('user_id', $r->user()->id)->orWhere('is_shared', true));
        if (! $r->user()->can('invoices.view')) {
            $q->where('requires_finance', false);
        }

        return RecordResource::collection($q->orderBy('name')->orderBy('id')->get());
    }

    public function store(Request $r): RecordResource
    {
        $d = $r->validate(['company_id' => ['required', 'integer', 'min:1'], 'module' => ['required', Rule::in(self::MODULES)], 'name' => ['required', 'string', 'max:80'], 'filters' => ['present', 'array', 'max:30'], 'filters.*' => ['string', 'max:500'], 'is_shared' => ['sometimes', 'boolean']]);
        CompanyAccess::company($r->user(), $d['company_id']);
        $this->authorizeModule($r, $d['module']);
        abort_if(($d['is_shared'] ?? false) && ! $this->sharedAdmin($r), 403);
        $f = $d['filters'];
        $money = count(array_intersect(array_keys($f), ['min_spend_minor', 'max_spend_minor', 'has_open_ar'])) > 0 || in_array($f['sort'] ?? null, RollupQuery::MONEY_SORTS, true);
        abort_if($money && ! $r->user()->can('invoices.view'), 403);

        return DB::transaction(function () use ($r, $d, $money): RecordResource {
            Company::whereKey($d['company_id'])->lockForUpdate()->firstOrFail();
            if (SavedFilter::where('company_id', $d['company_id'])->where('module', $d['module'])->count() >= 50) {
                throw ValidationException::withMessages(['module' => 'This module already has 50 saved views.']);
            }

            return new RecordResource(SavedFilter::create($d + ['user_id' => $r->user()->id, 'requires_finance' => $money]));
        }, 3);
    }

    public function destroy(Request $r, int $id): Response
    {
        $record = SavedFilter::findOrFail($id);
        CompanyAccess::company($r->user(), $record->company_id);
        abort_unless($record->user_id === $r->user()->id || ($record->is_shared && $this->sharedAdmin($r)), 403);
        $record->delete();

        return response()->noContent();
    }
}
