<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JournalLine;
use App\Models\Rental;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MilestoneThirteenTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function workspace(): array
    {
        $this->freezeTime();
        $this->seed(RolePermissionSeeder::class);
        $c = Company::factory()->create();
        $b = Branch::factory()->create(['company_id' => $c->id]);
        $u = User::factory()->create();
        $u->assignRole('company-admin');
        $u->companies()->attach($c->id);
        Sanctum::actingAs($u);
        $v = Vehicle::factory()->create(['company_id' => $c->id, 'branch_id' => $b->id, 'odometer' => 1000, 'status' => 'available']);

        return [$u, $c, $b, $v];
    }

    public function test_fuel_efficiency_odometer_and_atomic_gl_expense(): void
    {
        [$u,$c,$b,$v] = $this->workspace();
        $d = ['company_id' => $c->id, 'branch_id' => $b->id, 'vehicle_id' => $v->id, 'fuel_date' => now()->toDateString(), 'litres' => 20, 'amount_minor' => 10000, 'odometer' => 1000];
        $this->postJson('/api/v1/fuel-transactions', $d)->assertCreated()->assertJsonPath('data.litres_per_100km', null);
        $this->postJson('/api/v1/fuel-transactions', array_replace($d, ['odometer' => 1200]))->assertCreated()->assertJsonPath('data.litres_per_100km', 10)->assertJsonPath('data.is_abnormal', false);
        $this->postJson('/api/v1/fuel-transactions', array_replace($d, ['odometer' => 1199]))->assertUnprocessable();
        $this->assertDatabaseCount('expenses', 2);
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertSame(20000, (int) JournalLine::sum('debit_minor'));
        $this->assertSame(20000, (int) JournalLine::sum('credit_minor'));
        $this->assertSame(1200, $v->fresh()->odometer);
    }

    public function test_failed_release_keeps_vehicle_unavailable_and_passed_release_updates_schedule(): void
    {
        [$u,$c,$b,$v] = $this->workspace();
        $scope = ['company_id' => $c->id, 'branch_id' => $b->id, 'vehicle_id' => $v->id];
        $schedule = $this->postJson('/api/v1/maintenance-schedules', $scope + ['service_name' => 'Oil', 'interval_km' => 1000, 'last_service_odometer' => 0])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/maintenance-schedules?company_id='.$c->id)->assertOk()->assertJsonPath('data.0.due_status', 'overdue');
        $wo = $this->postJson('/api/v1/work-orders', $scope + ['title' => 'Service', 'due_at' => now()->toISOString(), 'schedule_id' => $schedule])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/work-orders/'.$wo.'/start')->assertOk();
        $this->postJson('/api/v1/work-orders/'.$wo.'/complete', ['release_passed' => false, 'release_notes' => 'Leaks remain'])->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->assertSame('maintenance', $v->fresh()->status);
        $this->postJson('/api/v1/work-orders/'.$wo.'/complete', ['release_passed' => true])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame('available', $v->fresh()->status);
        $this->assertDatabaseHas('maintenance_schedules', ['id' => $schedule, 'last_service_odometer' => 1000]);
    }

    public function test_rate_cards_scope_and_component_replacement(): void
    {
        [$u,$c,$b,$v] = $this->workspace();
        $d = ['company_id' => $c->id, 'branch_id' => $b->id, 'name' => 'Corporate', 'daily_rate_minor' => 100];
        $id = $this->postJson('/api/v1/rate-cards', $d)->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/rate-cards/'.$id, ['daily_rate_minor' => 0])->assertUnprocessable();
        $other = User::factory()->create();
        $other->assignRole('operations');
        Sanctum::actingAs($other);
        $this->patchJson('/api/v1/rate-cards/'.$id, ['daily_rate_minor' => 200])->assertNotFound();
        Sanctum::actingAs($u);
        $part = ['company_id' => $c->id, 'branch_id' => $b->id, 'vehicle_id' => $v->id, 'component_type' => 'tyre', 'serial_number' => 'TYRE-1', 'brand_model' => 'Road', 'position' => 'FL', 'installed_at' => now()->toDateString()];
        $first = $this->postJson('/api/v1/vehicle-components', $part)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/vehicle-components', array_replace($part, ['serial_number' => 'TYRE-2']))->assertCreated();
        $this->assertDatabaseHas('vehicle_components', ['id' => $first, 'status' => 'replaced']);
    }

    public function test_handover_return_inspection_difference(): void
    {
        [$u,$c,$b,$v] = $this->workspace();
        $customer = Customer::factory()->create(['company_id' => $c->id, 'branch_id' => $b->id]);
        $reservation = Reservation::create(['company_id' => $c->id, 'branch_id' => $b->id, 'vehicle_id' => $v->id, 'customer_id' => $customer->id, 'created_by' => $u->id, 'starts_at' => now(), 'ends_at' => now()->addDay(), 'status' => 'confirmed']);
        $rental = Rental::create(['company_id' => $c->id, 'branch_id' => $b->id, 'vehicle_id' => $v->id, 'reservation_id' => $reservation->id, 'status' => 'draft']);
        $this->postJson('/api/v1/rentals/'.$rental->id.'/handover', ['odometer' => 1000, 'notes' => 'Out', 'inspection' => ['fuel_level_pct' => 100]])->assertOk();
        $this->postJson('/api/v1/rentals/'.$rental->id.'/return', ['odometer' => 1100, 'notes' => 'Back', 'vehicle_status' => 'damaged', 'inspection' => ['fuel_level_pct' => 75, 'exterior_ok' => false]])->assertOk()->assertJsonPath('data.inspection_diff.fuel_shortage_pct', 25)->assertJsonPath('data.inspection_diff.newly_failed_checks', ['exterior_ok'])->assertJsonPath('data.inspection_diff.distance_km', 100);
        $this->assertDatabaseCount('vehicle_inspections', 2);
    }
}
