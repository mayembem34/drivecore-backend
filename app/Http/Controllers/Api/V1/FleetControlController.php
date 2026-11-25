<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PostJournal;
use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\FuelTransaction;
use App\Models\MaintenanceSchedule;
use App\Models\RateCard;
use App\Models\Rental;
use App\Models\Vehicle;
use App\Models\VehicleComponent;
use App\Models\VehicleInspection;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use App\Support\OperationalAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FleetControlController extends Controller
{
    private const MODELS = ['rate-cards' => RateCard::class, 'maintenance-schedules' => MaintenanceSchedule::class, 'vehicle-components' => VehicleComponent::class, 'fuel-transactions' => FuelTransaction::class, 'vehicle-inspections' => VehicleInspection::class];

    private function ability(string $domain, string $action): string
    {
        return match ($domain) {
            'rate-cards' => 'rate_cards.'.$action,'fuel-transactions' => 'fuel.'.$action,'vehicle-inspections' => 'inspections.'.$action,default => 'work_orders.'.$action
        };
    }

    private function scope(Request $r, string $domain): Builder
    {
        $q = CompanyAccess::scope(self::MODELS[$domain]::query(), $r->user());
        if ($r->user()->hasRole('driver') && ! $r->user()->can($this->ability($domain, 'view'))) {
            abort_unless(in_array($domain, ['fuel-transactions', 'vehicle-inspections'], true), 403);
            $q->whereIn('rental_id', OperationalAccess::rentals($r->user())->where('driver_user_id', $r->user()->id)->select('rentals.id'));
        } else {
            Gate::authorize($this->ability($domain, 'view'));
        }

        return $q;
    }

    public function index(Request $r, string $domain): AnonymousResourceCollection
    {
        $q = DirectoryQuery::apply($r, $this->scope($r, $domain), [], integerFilters: $domain === 'rate-cards' ? ['customer_id'] : ['vehicle_id']);
        $records = $q->orderByDesc('id')->paginate(25)->withQueryString();
        if ($domain === 'maintenance-schedules') {
            $vehicles = Vehicle::whereIn('id', collect($records->items())->pluck('vehicle_id'))->get()->keyBy('id');
            $records->getCollection()->each(function (MaintenanceSchedule $s) use ($vehicles): void {
                $s->setAttribute('due_status', $this->dueStatus($s, $vehicles[$s->vehicle_id]));
            });
        }

        return RecordResource::collection($records);
    }

    public function dueStatus(MaintenanceSchedule $s, Vehicle $v): string
    {
        if (! $s->is_active) {
            return 'inactive';
        } $km = $s->interval_km ? $s->last_service_odometer + $s->interval_km - $v->odometer : null;
        $days = $s->interval_days ? ($s->last_service_at ?? $s->created_at)->startOfDay()->addDays($s->interval_days)->diffInDays(now()->startOfDay(), false) : null;

        return (($km !== null && $km <= 0) || ($days !== null && $days >= 0)) ? 'overdue' : ((($km !== null && $km <= 500) || ($days !== null && $days >= -7)) ? 'due_soon' : 'scheduled');
    }

    public function save(Request $r, string $domain, ?int $id = null): RecordResource
    {
        Gate::authorize($this->ability($domain, 'manage'));
        $create = $id === null;
        $need = $create ? 'required' : 'sometimes';
        $rules = ['name' => [$need, 'string', 'max:120']];
        if ($domain === 'rate-cards') {
            $rules += ['customer_id' => ['nullable', 'integer', 'min:1'], 'vehicle_category' => ['nullable', 'string', 'max:64'], 'daily_rate_minor' => [$need, 'integer', 'min:1', 'max:1000000000000'], 'fuel_policy' => ['sometimes', Rule::in(['full_to_full', 'same_to_same', 'prepaid'])], 'is_active' => ['sometimes', 'boolean']];
            foreach (['weekly_rate_minor', 'monthly_rate_minor', 'chauffeur_daily_minor', 'daily_km_limit', 'excess_km_rate_minor', 'security_deposit_minor', 'late_return_hourly_minor'] as $field) {
                $rules[$field] = ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000000'];
            }
        } elseif ($domain === 'maintenance-schedules') {
            $rules = ['service_name' => [$need, 'string', 'max:120'], 'interval_km' => ['nullable', Rule::requiredIf($create && ! $r->filled('interval_days')), 'integer', 'min:1'], 'interval_days' => ['nullable', Rule::requiredIf($create && ! $r->filled('interval_km')), 'integer', 'min:1'], 'last_service_odometer' => ['sometimes', 'integer', 'min:0'], 'last_service_at' => ['nullable', 'date'], 'is_active' => ['sometimes', 'boolean']];
        } elseif ($domain === 'vehicle-components') {
            $rules = ['component_type' => [$need, Rule::in(['tyre', 'battery'])], 'serial_number' => [$need, 'string', 'max:80'], 'brand_model' => [$need, 'string', 'max:120'], 'position' => [$need, Rule::in(['FL', 'FR', 'RL', 'RR', 'SPARE', 'BATTERY'])], 'installed_at' => [$need, 'date'], 'installed_odometer' => ['sometimes', 'integer', 'min:0'], 'warranty_expires_at' => ['nullable', 'date'], 'status' => ['sometimes', Rule::in(['active', 'replaced', 'scrapped'])], 'replaced_at' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:10000']];
        } else {
            abort(405);
        }
        if ($create) {
            $rules += ['company_id' => ['required', 'integer', 'min:1'], 'branch_id' => [$domain === 'rate-cards' ? 'nullable' : 'required', 'integer', 'min:1']];
            if ($domain !== 'rate-cards') {
                $rules['vehicle_id'] = ['required', 'integer', 'min:1'];
            }
        }
        $d = $r->validate($rules);
        $existing = $id ? CompanyAccess::record($r->user(), self::MODELS[$domain], $id) : null;

        return DB::transaction(function () use ($r, $domain, $d, $existing): RecordResource {
            $data = $d;
            $company = $existing?->company_id ?? $d['company_id'];
            $branch = $existing?->branch_id ?? ($d['branch_id'] ?? null);
            $c = CompanyAccess::company($r->user(), $company);
            if ($branch) {
                CompanyAccess::branch($r->user(), $company, $branch);
            } else {
                abort_unless($r->user()->is_platform_admin || $c->pivot->branch_id === null, 403);
            }
            if (isset($data['customer_id'])) {
                $customer = CompanyAccess::record($r->user(), Customer::class, $data['customer_id']);
                abort_unless($customer->company_id === $company && (! $branch || $customer->branch_id === $branch), 404);
            }
            if ($domain !== 'rate-cards') {
                $v = Vehicle::where('company_id', $company)->where('branch_id', $branch)->lockForUpdate()->findOrFail($existing?->vehicle_id ?? $data['vehicle_id']);
            }
            $record = $existing ? self::MODELS[$domain]::lockForUpdate()->findOrFail($existing->id) : new (self::MODELS[$domain]);
            $record->fill($data);
            if ($domain === 'maintenance-schedules' && ! $record->interval_km && ! $record->interval_days) {
                throw ValidationException::withMessages(['interval_km' => 'Specify a distance or time service interval.']);
            }
            if ($domain === 'vehicle-components') {
                if (($record->component_type === 'battery') !== ($record->position === 'BATTERY')) {
                    throw ValidationException::withMessages(['position' => 'Battery components require BATTERY position; tyres require a wheel position.']);
                } if ($record->status === null || $record->status === 'active') {
                    VehicleComponent::where('vehicle_id', $v->id)->where('position', $record->position)->where('status', 'active')->when($record->exists, fn ($q) => $q->whereKeyNot($record->id))->update(['status' => 'replaced', 'replaced_at' => $record->installed_at]);
                }
            }
            $record->save();
            RecordAudit::write($r->user(), str_replace('-', '_', $domain).'.saved', $record);

            return new RecordResource($record->refresh());
        }, 3);
    }

    public function fuel(Request $r, PostJournal $journal): RecordResource
    {
        $d = $r->validate(['company_id' => ['required', 'integer', 'min:1'], 'branch_id' => ['required', 'integer', 'min:1'], 'vehicle_id' => ['required', 'integer', 'min:1'], 'driver_user_id' => ['nullable', 'integer', 'min:1'], 'rental_id' => ['nullable', 'integer', 'min:1'], 'fuel_date' => ['required', 'date'], 'station' => ['nullable', 'string', 'max:120'], 'litres' => ['required', 'numeric', 'gt:0', 'max:999999.99'], 'amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'], 'odometer' => ['required', 'integer', 'min:0'], 'payment_method' => ['sometimes', Rule::in(['cash', 'card', 'bank_transfer', 'mobile_money'])]]);
        CompanyAccess::branch($r->user(), $d['company_id'], $d['branch_id']);

        return DB::transaction(function () use ($r, $d, $journal): RecordResource {
            $vehicle = CompanyAccess::scope(Vehicle::query(), $r->user())->where('company_id', $d['company_id'])->where('branch_id', $d['branch_id'])->lockForUpdate()->findOrFail($d['vehicle_id']);
            if (isset($d['rental_id'])) {
                $rental = OperationalAccess::rentals($r->user())->where('vehicle_id', $vehicle->id)->where('status', 'active')->findOrFail($d['rental_id']);
            }
            if (! $r->user()->can('fuel.manage')) {
                abort_unless($r->user()->hasRole('driver') && isset($rental) && $rental->driver_user_id === $r->user()->id, 403);
            }
            if (isset($d['driver_user_id'])) {
                OperationalAccess::validateDriver($d['driver_user_id'], new Rental(['company_id' => $vehicle->company_id, 'branch_id' => $vehicle->branch_id]));
            }
            if (! $r->user()->can('fuel.manage')) {
                $d['driver_user_id'] = $r->user()->id;
            }
            $last = FuelTransaction::where('vehicle_id', $vehicle->id)->orderByDesc('odometer')->orderByDesc('id')->first();
            if ($d['odometer'] < $vehicle->odometer || ($last && ($d['odometer'] < $last->odometer || $r->date('fuel_date')->lt($last->fuel_date)))) {
                throw ValidationException::withMessages(['odometer' => 'Odometer and fuel date cannot go backwards.']);
            }
            $km = $last ? $d['odometer'] - $last->odometer : null;
            $efficiency = $km > 0 ? round($d['litres'] / $km * 100, 2) : null;
            $expense = Expense::create(['company_id' => $vehicle->company_id, 'branch_id' => $vehicle->branch_id, 'vehicle_id' => $vehicle->id, 'recorded_by' => $r->user()->id, 'category' => 'fuel', 'description' => 'Fuel: '.($d['station'] ?? $vehicle->registration), 'amount_minor' => $d['amount_minor'], 'incurred_at' => $d['fuel_date']]);
            $record = FuelTransaction::create($d + ['expense_id' => $expense->id, 'km_since_last' => $km, 'litres_per_100km' => $efficiency, 'cost_per_km_minor' => $km > 0 ? (int) round($d['amount_minor'] / $km) : null, 'is_abnormal' => $efficiency !== null && ($efficiency > 30 || $efficiency < 2)]);
            $vehicle->update(['odometer' => $d['odometer']]);
            $journal->handle($r->user(), $vehicle->company_id, $vehicle->branch_id, 'expense', $expense->id, [['5000', $expense->amount_minor, 0], ['1000', 0, $expense->amount_minor]], 'FUEL-'.$record->id, 'Fuel purchase');
            RecordAudit::write($r->user(), 'fuel.created', $record);

            return new RecordResource($record->refresh());
        }, 3);
    }
}
