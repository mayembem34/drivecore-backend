<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FixedAsset;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MilestoneSixteenFiveTest extends TestCase
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

        return [$u, $c, $b];
    }

    public function test_vehicle_digital_file_financial_redaction_and_authoritative_asset_book_value(): void
    {
        [$u,$c,$b] = $this->workspace();
        $payload = ['company_id' => $c->id, 'branch_id' => $b->id, 'registration' => 'DIGITAL', 'make' => 'Make', 'model' => 'Model', 'vin' => 'VIN123', 'category' => 'suv', 'fuel_type' => 'diesel', 'transmission' => 'automatic', 'current_location' => 'Depot', 'insurance_expires_at' => now()->addYear()->toDateString(), 'acquisition_cost_minor' => 10000, 'current_book_value_minor' => 9999];
        $id = $this->postJson('/api/v1/vehicles', $payload)->assertCreated()->assertJsonPath('data.vin', 'VIN123')->json('data.id');
        FixedAsset::create(['company_id' => $c->id, 'branch_id' => $b->id, 'vehicle_id' => $id, 'asset_code' => 'DIGITAL', 'name' => 'Vehicle', 'acquisition_cost_minor' => 10000, 'accumulated_depreciation_minor' => 1000, 'capitalisation_date' => now(), 'useful_life_months' => 12]);
        $this->getJson('/api/v1/vehicles/'.$id)->assertOk()->assertJsonPath('data.current_book_value_minor', 9000);
        $u->syncRoles('operations');
        $this->getJson('/api/v1/vehicles/'.$id)->assertOk()->assertJsonMissingPath('data.acquisition_cost_minor')->assertJsonMissingPath('data.current_book_value_minor');
        $this->patchJson('/api/v1/vehicles/'.$id, array_replace($payload, ['acquisition_cost_minor' => 1]))->assertForbidden();
        $this->getJson('/api/v1/vehicles/'.$id.'/history')->assertOk()->assertJsonPath('data.rentals', [])->assertJsonPath('data.incidents', []);
    }

    public function test_incidents_restrict_booking_and_link_one_work_order(): void
    {
        [$u,$c,$b] = $this->workspace();
        $v = Vehicle::factory()->create(['company_id' => $c->id, 'branch_id' => $b->id, 'status' => 'available']);
        $customer = Customer::factory()->create(['company_id' => $c->id, 'branch_id' => $b->id]);
        $id = $this->postJson('/api/v1/incidents', ['company_id' => $c->id, 'branch_id' => $b->id, 'vehicle_id' => $v->id, 'incident_type' => 'accident', 'severity' => 'major', 'occurred_at' => now()->toDateString(), 'description' => 'Collision'])->assertCreated()->json('data.id');
        $this->assertSame('accident', $v->fresh()->status);
        $this->postJson('/api/v1/reservations', ['company_id' => $c->id, 'branch_id' => $b->id, 'vehicle_id' => $v->id, 'customer_id' => $customer->id, 'starts_at' => now()->toISOString(), 'ends_at' => now()->addDay()->toISOString()])->assertConflict();
        $this->postJson('/api/v1/incidents/'.$id.'/create-work-order')->assertCreated();
        $this->postJson('/api/v1/incidents/'.$id.'/create-work-order')->assertConflict();
        $this->assertDatabaseCount('work_orders', 1);
    }

    public function test_requisitions_require_independent_approval_and_convert_once(): void
    {
        [$u,$c,$b] = $this->workspace();
        $supplier = Supplier::create(['company_id' => $c->id, 'name' => 'Vendor']);
        $id = $this->postJson('/api/v1/purchase-requisitions', ['company_id' => $c->id, 'branch_id' => $b->id, 'supplier_id' => $supplier->id, 'department' => 'Workshop', 'needed_by' => now()->addDay()->toDateString(), 'justification' => 'Service', 'items' => [['description' => 'External service', 'quantity' => 2, 'estimated_unit_minor' => 100]]])->assertCreated()->assertJsonPath('data.estimated_total_minor', 200)->json('data.id');
        $this->postJson('/api/v1/purchase-requisitions/'.$id.'/approve')->assertForbidden();
        $this->postJson('/api/v1/purchase-requisitions/'.$id.'/convert-to-po')->assertConflict();
        $other = User::factory()->create();
        $other->assignRole('finance-manager');
        $other->companies()->attach($c->id);
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/purchase-requisitions/'.$id.'/approve')->assertOk();
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/purchase-requisitions/'.$id.'/convert-to-po')->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total_minor', 200);
        $this->postJson('/api/v1/purchase-requisitions/'.$id.'/convert-to-po')->assertConflict();
        $this->assertDatabaseCount('purchase_orders', 1);
    }

    public function test_kyc_acceptance_contract_amendment_deposit_and_signature(): void
    {
        [$u,$c,$b] = $this->workspace();
        $customer = $this->postJson('/api/v1/customers', ['company_id' => $c->id, 'branch_id' => $b->id, 'name' => 'Corporate', 'type' => 'corporate', 'tin' => 'TIN123', 'contact_person_name' => 'Contact', 'billing_address' => 'Address'])->assertCreated()->assertJsonPath('data.tin', 'TIN123')->json('data.id');
        $v = Vehicle::factory()->create(['company_id' => $c->id, 'branch_id' => $b->id, 'status' => 'available', 'odometer' => 1000, 'daily_rate_minor' => 100]);
        $quote = $this->postJson('/api/v1/quotations', ['company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customer, 'vehicle_ids' => [$v->id], 'starts_at' => now()->toISOString(), 'ends_at' => now()->addDays(3)->toISOString()])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/quotations/'.$quote.'/accept', ['customer_acceptance_ref' => 'Signed in office'])->assertOk()->assertJsonPath('data.customer_acceptance_ref', 'Signed in office');
        $res = $this->postJson('/api/v1/quotations/'.$quote.'/convert')->assertOk()->json('data.reservation_ids.0');
        $rental = $this->postJson('/api/v1/rentals', ['reservation_id' => $res])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/rentals/'.$rental.'/amend', ['type' => 'rate_change', 'daily_rate_minor' => 200, 'reason' => 'New agreed rate'])->assertOk()->assertJsonPath('data.amended_daily_rate_minor', 200);
        $this->postJson('/api/v1/rentals/'.$rental.'/deposit', ['status' => 'held', 'amount_minor' => 1000, 'reason' => 'Deposit received'])->assertOk()->assertJsonPath('data.deposit_status', 'held');
        $this->postJson('/api/v1/rentals/'.$rental.'/deposit', ['status' => 'refunded', 'reason' => 'Refunded'])->assertOk()->assertJsonPath('data.deposit_status', 'refunded');
        $signature = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9d0AAAAASUVORK5CYII=';
        $this->postJson('/api/v1/rentals/'.$rental.'/handover', ['odometer' => 1000, 'notes' => 'Out', 'inspection' => ['fuel_level_pct' => 100, 'signature_data_url' => $signature]])->assertOk();
        $this->assertDatabaseHas('vehicle_inspections', ['rental_id' => $rental, 'signature_data_url' => $signature]);
    }

    public function test_threshold_matrix_and_financial_reports_access(): void
    {
        [$u,$c,$b] = $this->workspace();
        $rows = [];
        foreach (['discount', 'credit_limit', 'vehicle_repair', 'purchase', 'refund_writeoff', 'contract_amendment', 'vehicle_disposal'] as $type) {
            $rows[] = ['type' => $type, 'threshold_minor' => 1000, 'approver_role' => 'finance-manager'];
        } $this->patchJson('/api/v1/companies/'.$c->id.'/approval-thresholds', ['thresholds' => $rows])->assertOk()->assertJsonCount(7, 'data');
        $this->getJson('/api/v1/reports/statements/vat-return?company_id='.$c->id)->assertOk()->assertJsonPath('data.net_vat_minor', 0);
        $u->syncRoles('operations');
        $this->patchJson('/api/v1/companies/'.$c->id.'/approval-thresholds', ['thresholds' => $rows])->assertForbidden();
        $this->getJson('/api/v1/reports/statements/vat-return?company_id='.$c->id)->assertForbidden();
    }
}
