<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SavedFilter;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MilestoneElevenTest extends TestCase
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

    public function test_customer_spend_sql_sort_crosses_pages_and_ar_is_live(): void
    {
        [$u,$c,$b] = $this->workspace();
        $ids = [];
        for ($n = 1; $n <= 27; $n++) {
            $customer = Customer::factory()->create(['company_id' => $c->id, 'branch_id' => $b->id]);
            $ids[] = $customer->id;
            $invoice = Invoice::create(['company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customer->id, 'created_by' => $u->id, 'invoice_number' => 'METRIC-'.$n, 'status' => 'issued', 'issued_at' => now()->subMonths(2), 'due_at' => now(), 'total_minor' => 10000]);
            Payment::create(['company_id' => $c->id, 'branch_id' => $b->id, 'invoice_id' => $invoice->id, 'recorded_by' => $u->id, 'amount_minor' => $n * 100, 'method' => 'cash', 'reference' => 'R', 'paid_at' => now()]);
        }
        $url = '/api/v1/stats/customers?company_id='.$c->id.'&sort=total_spent_minor&dir=desc';
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $ids[26])->assertJsonPath('data.24.id', $ids[2]);
        $this->getJson($url.'&page=2')->assertOk()->assertJsonPath('data.0.id', $ids[1])->assertJsonPath('data.1.id', $ids[0]);
        $this->getJson('/api/v1/customers/'.$ids[26].'/summary?from='.now()->addDay()->toDateString())->assertOk()->assertJsonPath('data.total_spent_minor', 0)->assertJsonPath('data.open_ar_minor', 7300);
        $u->syncRoles('operations');
        $this->getJson('/api/v1/stats/customers?company_id='.$c->id)->assertOk()->assertJsonPath('data.0.stats.open_ar_minor', null)->assertJsonPath('meta.summary.total_spent_minor', null);
        foreach (['min_spend_minor=0', 'has_open_ar=1', 'sort=total_spent_minor'] as $filter) {
            $this->getJson('/api/v1/customers?company_id='.$c->id.'&'.$filter)->assertForbidden();
        }
        $this->getJson('/api/v1/customers/'.$ids[0].'/summary')->assertOk()->assertJsonPath('data.recent_invoices', [])->assertJsonPath('data.total_invoiced_minor', null);
    }

    public function test_saved_filter_visibility_ownership_and_company_cap(): void
    {
        [$u,$c,$b] = $this->workspace();
        $body = ['company_id' => $c->id, 'module' => 'customers', 'name' => 'Debtors', 'filters' => ['has_open_ar' => '1'], 'is_shared' => true];
        $this->postJson('/api/v1/saved-filters', $body)->assertCreated()->assertJsonPath('data.requires_finance', true);
        $personal = $this->postJson('/api/v1/saved-filters', array_replace($body, ['name' => 'Private', 'filters' => [], 'is_shared' => false]))->assertCreated()->json('data.id');
        $other = User::factory()->create();
        $other->assignRole('operations');
        $other->companies()->attach($c->id);
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/saved-filters?company_id='.$c->id.'&module=customers')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/saved-filters', $body)->assertForbidden();
        $this->deleteJson('/api/v1/saved-filters/'.$personal)->assertForbidden();
        Sanctum::actingAs($u);
        for ($n = 0; $n < 48; $n++) {
            SavedFilter::create(['company_id' => $c->id, 'user_id' => $u->id, 'module' => 'customers', 'name' => 'Saved '.$n, 'filters' => []]);
        } $this->postJson('/api/v1/saved-filters', array_replace($body, ['filters' => []]))->assertUnprocessable();
        $this->deleteJson('/api/v1/saved-filters/'.$personal)->assertNoContent();
    }

    public function test_vehicle_rollups_and_branch_scope(): void
    {
        [$u,$c,$b] = $this->workspace();
        Vehicle::factory()->create(['company_id' => $c->id, 'branch_id' => $b->id, 'daily_rate_minor' => 0, 'functions' => ['chauffeur', 'delivery'], 'created_at' => now()->subDays(20)]);
        $this->getJson('/api/v1/stats/vehicles?company_id='.$c->id.'&functions=chauffeur,delivery&unpriced_only=1&sort=idle_days')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.stats.idle_days', 20)->assertJsonPath('meta.summary.unpriced_count', 1);
        $u->syncRoles('operations');
        $this->getJson('/api/v1/stats/vehicles?company_id='.$c->id)->assertOk()->assertJsonPath('data.0.stats.revenue_minor', null)->assertJsonPath('meta.summary.total_net_roi_minor', null);
        Branch::factory()->create(['company_id' => $c->id]);
        $u->companies()->updateExistingPivot($c->id, ['branch_id' => $b->id]);
        $this->getJson('/api/v1/stats/branches?company_id='.$c->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.branch_id', $b->id);
    }
}
