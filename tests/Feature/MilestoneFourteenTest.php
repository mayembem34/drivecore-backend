<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MilestoneFourteenTest extends TestCase
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

    public function test_credit_hold_blocks_booking_but_allows_payment_and_release(): void
    {
        [$u,$c,$b] = $this->workspace();
        $customer = Customer::factory()->create(['company_id' => $c->id, 'branch_id' => $b->id]);
        $v = Vehicle::factory()->create(['company_id' => $c->id, 'branch_id' => $b->id, 'status' => 'available']);
        $this->patchJson('/api/v1/customers/'.$customer->id.'/credit', ['is_on_credit_hold' => true, 'credit_hold_reason' => 'Overdue'])->assertOk();
        $payload = ['company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customer->id, 'vehicle_id' => $v->id, 'starts_at' => now()->toISOString(), 'ends_at' => now()->addDay()->toISOString()];
        $this->postJson('/api/v1/reservations', $payload)->assertConflict();
        $i = Invoice::create(['company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customer->id, 'created_by' => $u->id, 'invoice_number' => 'HOLD', 'status' => 'issued', 'issued_at' => now(), 'due_at' => now(), 'total_minor' => 100]);
        $this->postJson('/api/v1/invoices/'.$i->id.'/payments', ['amount_minor' => 100, 'method' => 'cash', 'reference' => 'Paid'])->assertCreated();
        $this->getJson('/api/v1/customers/'.$customer->id.'/statement')->assertOk()->assertJsonPath('data.closing_balance_minor', 0);
        $this->patchJson('/api/v1/customers/'.$customer->id.'/credit', ['is_on_credit_hold' => false])->assertOk();
        $this->postJson('/api/v1/reservations', $payload)->assertCreated();
    }

    public function test_no_self_approval_and_no_duplicate_decision(): void
    {
        [$u,$c,$b] = $this->workspace();
        $id = $this->postJson('/api/v1/approvals', ['company_id' => $c->id, 'branch_id' => $b->id, 'type' => 'purchase', 'title' => 'Purchase', 'requested_value' => '1000', 'reason' => 'Stock'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/approvals/'.$id.'/approve')->assertForbidden();
        $second = User::factory()->create();
        $second->assignRole('finance');
        $second->companies()->attach($c->id);
        Sanctum::actingAs($second);
        $this->postJson('/api/v1/approvals/'.$id.'/approve', ['decision_notes' => 'Checked'])->assertOk()->assertJsonPath('data.approver_id', $second->id)->assertJsonPath('data.approved_value', '1000');
        $this->postJson('/api/v1/approvals/'.$id.'/reject')->assertConflict();
        $this->assertDatabaseHas('approval_requests', ['id' => $id, 'status' => 'approved', 'requester_id' => $u->id, 'approver_id' => $second->id]);
    }

    public function test_compliance_expiry_buckets_and_scope(): void
    {
        [$u,$c,$b] = $this->workspace();
        foreach ([-1, 7, 14, 30, 31] as $days) {
            Document::create(['company_id' => $c->id, 'branch_id' => $b->id, 'uploaded_by' => $u->id, 'disk' => 'local', 'path' => 'doc.pdf', 'original_name' => 'doc.pdf', 'mime_type' => 'application/pdf', 'size' => 1, 'expires_at' => now()->addDays($days), 'category' => 'insurance']);
        } $this->getJson('/api/v1/compliance/expiries?company_id='.$c->id)->assertOk()->assertJsonPath('data.buckets', ['expired' => 1, 'd7' => 1, 'd14' => 1, 'd30' => 1, 'valid' => 1]);
    }

    public function test_configured_credit_limit_requires_a_matching_independent_approval_and_consumes_it(): void
    {
        [$u,$c,$b] = $this->workspace();
        $customer = Customer::factory()->create(['company_id' => $c->id, 'branch_id' => $b->id]);
        $this->patchJson('/api/v1/companies/'.$c->id.'/approval-thresholds', ['thresholds' => [['type' => 'credit_limit', 'threshold_minor' => 100, 'approver_role' => 'finance-manager']]])->assertOk();
        $this->patchJson('/api/v1/customers/'.$customer->id.'/credit', ['credit_limit_minor' => 1000])->assertConflict();
        $id = $this->postJson('/api/v1/approvals', ['company_id' => $c->id, 'branch_id' => $b->id, 'type' => 'credit_limit', 'entity_type' => 'customer', 'entity_id' => $customer->id, 'title' => 'Credit limit', 'requested_value' => '1000', 'reason' => 'Reviewed corporate account'])->assertCreated()->json('data.id');
        $other = User::factory()->create();
        $other->assignRole('finance-manager');
        $other->companies()->attach($c->id);
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/approvals/'.$id.'/approve')->assertOk();
        Sanctum::actingAs($u);
        $this->patchJson('/api/v1/customers/'.$customer->id.'/credit', ['credit_limit_minor' => 1000, 'approval_request_id' => $id])->assertOk()->assertJsonPath('data.credit_limit_minor', 1000);
        $this->patchJson('/api/v1/customers/'.$customer->id.'/credit', ['credit_limit_minor' => 1000, 'approval_request_id' => $id])->assertConflict();
        $this->assertNotNull(ApprovalRequest::findOrFail($id)->applied_at);
    }
}
