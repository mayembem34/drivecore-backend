<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\Refund;
use App\Models\Rental;
use App\Models\Reservation;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MilestoneNineTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function workspace(): array
    {
        $this->freezeTime();
        $this->seed(RolePermissionSeeder::class);
        $company = Company::factory()->create();
        $branch = Branch::factory()->create(['company_id' => $company->id]);
        $user = User::factory()->create();
        $user->assignRole('company-admin');
        $user->companies()->attach($company->id);
        Sanctum::actingAs($user);

        return [$user, $company, $branch];
    }

    private function part(Company $company, Branch $branch, string $sku = 'PART'): int
    {
        return $this->postJson('/api/v1/parts', ['company_id' => $company->id, 'branch_id' => $branch->id, 'sku' => $sku, 'name' => $sku, 'unit_cost_minor' => 100, 'min_stock' => 2])->assertCreated()->assertJsonPath('data.on_hand', 0)->assertJsonPath('data.is_low_stock', true)->json('data.id');
    }

    private function order(Company $company, Branch $branch, Vehicle $vehicle): int
    {
        return $this->postJson('/api/v1/work-orders', ['company_id' => $company->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicle->id, 'title' => 'Service', 'due_at' => now()->addDay()->toISOString(), 'labour_cost_minor' => 500])->assertCreated()->json('data.id');
    }

    public function test_multi_line_purchase_receipt_is_once_only_and_requires_finance_approval(): void
    {
        [$actor,$company,$branch] = $this->workspace();
        $first = $this->part($company, $branch, 'OIL');
        $second = $this->part($company, $branch, 'FILTER');
        $supplier = $this->postJson('/api/v1/suppliers', ['company_id' => $company->id, 'name' => 'Supplier'])->assertCreated()->assertJsonPath('data.is_active', true)->json('data.id');
        $payload = ['company_id' => $company->id, 'branch_id' => $branch->id, 'supplier_id' => $supplier, 'lines' => [['part_id' => $first, 'quantity' => 4, 'unit_cost_minor' => 100], ['part_id' => $second, 'quantity' => 3, 'unit_cost_minor' => 100]]];
        $po = $this->postJson('/api/v1/purchase-orders', $payload)->assertCreated()->assertJsonPath('data.total_minor', 700)->json('data.id');
        $this->postJson('/api/v1/purchase-orders/'.$po.'/receive')->assertConflict();
        $this->postJson('/api/v1/purchase-orders/'.$po.'/submit')->assertOk();
        $actor->syncRoles('operations');
        $this->postJson('/api/v1/purchase-orders/'.$po.'/approve')->assertForbidden();
        $actor->syncRoles('finance');
        $this->postJson('/api/v1/purchase-orders/'.$po.'/approve')->assertForbidden();
        $approver = User::factory()->create();
        $approver->assignRole('finance');
        $approver->companies()->attach($company->id);
        Sanctum::actingAs($approver);
        $this->postJson('/api/v1/purchase-orders/'.$po.'/approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson('/api/v1/purchase-orders/'.$po.'/receive')->assertForbidden();
        $actor->syncRoles('operations');
        Sanctum::actingAs($actor);
        $this->postJson('/api/v1/purchase-orders/'.$po.'/receive')->assertOk()->assertJsonPath('data.status', 'received');
        $this->postJson('/api/v1/purchase-orders/'.$po.'/receive')->assertConflict();
        $this->postJson('/api/v1/purchase-orders/'.$po.'/cancel')->assertConflict();
        $this->assertSame(4, (int) StockMovement::where('part_id', $first)->sum('quantity'));
        $this->assertSame(3, (int) StockMovement::where('part_id', $second)->sum('quantity'));
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertSame(700, (int) JournalLine::sum('debit_minor'));
        $this->assertSame(700, (int) JournalLine::sum('credit_minor'));
    }

    public function test_work_order_consumption_completion_and_vehicle_restoration_are_atomic(): void
    {
        [$actor,$company,$branch] = $this->workspace();
        $part = $this->part($company, $branch);
        $vehicle = Vehicle::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'status' => 'available']);
        $order = $this->order($company, $branch, $vehicle);
        $this->postJson('/api/v1/parts/'.$part.'/adjust', ['quantity' => 3, 'reason' => 'Initial stock'])->assertOk()->assertJsonPath('data.on_hand', 3);
        $this->postJson('/api/v1/work-orders/'.$order.'/start')->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->assertSame('maintenance', $vehicle->fresh()->status);
        $this->postJson('/api/v1/work-orders/'.$order.'/consume-parts', ['part_id' => $part, 'quantity' => 4])->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements', 1);
        $this->postJson('/api/v1/work-orders/'.$order.'/consume-parts', ['part_id' => $part, 'quantity' => 2])->assertOk()->assertJsonPath('data.parts_cost_minor', 200);
        $this->postJson('/api/v1/work-orders/'.$order.'/complete', ['completion_notes' => 'Done'])->assertOk()->assertJsonPath('data.total_cost_minor', 700);
        $this->assertSame('available', $vehicle->fresh()->status);
        $this->assertDatabaseHas('expenses', ['vehicle_id' => $vehicle->id, 'amount_minor' => 700, 'category' => 'maintenance']);
        $this->assertDatabaseCount('expenses', 1);
        $this->postJson('/api/v1/work-orders/'.$order.'/complete')->assertConflict();
        $this->assertSame(1, (int) StockMovement::where('part_id', $part)->sum('quantity'));
        $this->assertSame((int) JournalLine::sum('debit_minor'), (int) JournalLine::sum('credit_minor'));
    }

    public function test_start_rejects_rented_reserved_and_already_serviced_vehicles(): void
    {
        [$actor,$company,$branch] = $this->workspace();
        $vehicle = Vehicle::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'status' => 'rented']);
        $order = $this->order($company, $branch, $vehicle);
        $this->postJson('/api/v1/work-orders/'.$order.'/start')->assertConflict();
        $vehicle->update(['status' => 'available']);
        $customer = Customer::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id]);
        $reservation = Reservation::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'created_by' => $actor->id, 'starts_at' => now(), 'ends_at' => now()->addDay(), 'status' => 'confirmed']);
        $this->postJson('/api/v1/work-orders/'.$order.'/start')->assertConflict();
        $reservation->update(['status' => 'cancelled']);
        $this->postJson('/api/v1/work-orders/'.$order.'/start')->assertOk();
        $second = $this->order($company, $branch, $vehicle);
        $this->postJson('/api/v1/work-orders/'.$second.'/start')->assertConflict();
        $this->postJson('/api/v1/work-orders/'.$order.'/cancel')->assertConflict();
        $this->postJson('/api/v1/work-orders/'.$second.'/cancel')->assertOk();
    }

    public function test_negative_adjustment_and_cross_branch_consumption_make_no_partial_writes(): void
    {
        [$actor,$company,$branch] = $this->workspace();
        $part = $this->part($company, $branch);
        $this->postJson('/api/v1/parts/'.$part.'/adjust', ['quantity' => -1, 'reason' => 'Too much'])->assertUnprocessable();
        $this->postJson('/api/v1/parts/'.$part.'/adjust', ['quantity' => 0, 'reason' => 'Zero'])->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $other = Branch::factory()->create(['company_id' => $company->id]);
        $foreign = $this->part($company, $other, 'OTHER');
        $vehicle = Vehicle::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'status' => 'available']);
        $order = $this->order($company, $branch, $vehicle);
        $this->postJson('/api/v1/work-orders/'.$order.'/start')->assertOk();
        $this->postJson('/api/v1/work-orders/'.$order.'/consume-parts', ['part_id' => $foreign, 'quantity' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_vehicle_summary_hides_finance_and_avoids_double_counting_maintenance_expenses(): void
    {
        [$actor,$company,$branch] = $this->workspace();
        $vehicle = Vehicle::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'status' => 'available']);
        $order = $this->order($company, $branch, $vehicle);
        $this->postJson('/api/v1/work-orders/'.$order.'/start')->assertOk();
        $this->travel(2)->hours();
        $this->postJson('/api/v1/work-orders/'.$order.'/complete')->assertOk();
        $this->postJson('/api/v1/expenses', ['company_id' => $company->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicle->id, 'category' => 'fuel', 'description' => 'Fuel', 'amount_minor' => 200, 'incurred_at' => now()->toISOString()])->assertCreated();
        $actor->syncRoles('operations');
        $this->getJson('/api/v1/vehicles/'.$vehicle->id.'/summary')->assertOk()->assertJsonPath('data.total_downtime_hours', 2)->assertJsonPath('data.cumulative_revenue_minor', null)->assertJsonPath('data.maintenance_cost_minor', null)->assertJsonPath('data.other_expenses_minor', null)->assertJsonPath('data.net_roi_minor', null);
        $customer = Customer::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id]);
        $reservation = Reservation::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicle->id, 'customer_id' => $customer->id, 'created_by' => $actor->id, 'starts_at' => now()->subDay(), 'ends_at' => now(), 'status' => 'completed']);
        $rental = Rental::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicle->id, 'reservation_id' => $reservation->id, 'status' => 'returned']);
        $invoice = Invoice::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'rental_id' => $rental->id, 'created_by' => $actor->id, 'invoice_number' => 'ROI', 'status' => 'issued', 'due_at' => now(), 'total_minor' => 1000]);
        Refund::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'invoice_id' => $invoice->id, 'recorded_by' => $actor->id, 'amount_minor' => 200, 'reason' => 'Adjustment', 'refunded_at' => now()]);
        $actor->syncRoles('finance');
        $this->getJson('/api/v1/vehicles/'.$vehicle->id.'/summary')->assertOk()->assertJsonPath('data.work_order_count', 1)->assertJsonPath('data.maintenance_cost_minor', 500)->assertJsonPath('data.other_expenses_minor', 200)->assertJsonPath('data.cumulative_revenue_minor', 800)->assertJsonPath('data.net_roi_minor', 100);
    }

    public function test_supplier_scope_is_company_wide_but_stock_and_orders_remain_branch_scoped(): void
    {
        [$actor,$company,$branch] = $this->workspace();
        $supplier = $this->postJson('/api/v1/suppliers', ['company_id' => $company->id, 'name' => 'Vendor'])->assertCreated()->json('data.id');
        $other = Branch::factory()->create(['company_id' => $company->id]);
        $part = $this->part($company, $other);
        $actor->companies()->updateExistingPivot($company->id, ['branch_id' => $branch->id]);
        $this->getJson('/api/v1/suppliers?company_id='.$company->id)->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson('/api/v1/suppliers/'.$supplier, ['phone' => '123'])->assertOk();
        $this->patchJson('/api/v1/parts/'.$part, ['name' => 'Hidden'])->assertNotFound();
        $this->getJson('/api/v1/parts?company_id='.$company->id.'&branch_id='.$other->id)->assertNotFound();
        $actor->syncRoles('viewer');
        $this->postJson('/api/v1/parts', [])->assertForbidden();
        $actor->syncRoles('driver');
        $this->getJson('/api/v1/parts?company_id='.$company->id)->assertForbidden();
        $this->getJson('/api/v1/suppliers?company_id='.$company->id)->assertForbidden();
    }

    public function test_draft_purchase_edits_and_inventory_filters_and_documents(): void
    {
        Storage::fake('local');
        [$actor, $company, $branch] = $this->workspace();
        $part = $this->part($company, $branch);
        $supplier = $this->postJson('/api/v1/suppliers', ['company_id' => $company->id, 'name' => 'Vendor'])->assertCreated()->json('data.id');
        $payload = ['company_id' => $company->id, 'branch_id' => $branch->id, 'supplier_id' => $supplier, 'lines' => [['part_id' => $part, 'quantity' => 2, 'unit_cost_minor' => 100]]];
        $id = $this->postJson('/api/v1/purchase-orders', $payload)->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/purchase-orders/'.$id, ['lines' => [['part_id' => $part, 'quantity' => 5, 'unit_cost_minor' => 120]]])->assertOk()->assertJsonPath('data.total_minor', 600)->assertJsonCount(1, 'data.lines');
        $this->postJson('/api/v1/purchase-orders/'.$id.'/submit')->assertOk();
        $this->patchJson('/api/v1/purchase-orders/'.$id, ['lines' => [['part_id' => $part, 'quantity' => 1, 'unit_cost_minor' => 1]]])->assertConflict();
        $this->postJson('/api/v1/documents', ['company_id' => $company->id, 'branch_id' => $branch->id, 'entity_type' => 'purchase_order', 'entity_id' => $id, 'file' => UploadedFile::fake()->image('receipt.jpg')])->assertCreated()->assertJsonPath('data.entity_id', $id);
        $this->getJson('/api/v1/parts?company_id='.$company->id.'&low_stock_only=1&sort=on_hand&dir=desc')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/parts/'.$part.'/adjust', ['quantity' => 3, 'reason' => 'New'])->assertOk()->assertJsonPath('data.is_low_stock', false);
        $this->getJson('/api/v1/parts?company_id='.$company->id.'&low_stock_only=1')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/stock-movements?company_id='.$company->id.'&part_id='.$part.'&sort=part_name')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/parts?company_id='.$company->id.'&sort=unknown')->assertUnprocessable();
    }
}
