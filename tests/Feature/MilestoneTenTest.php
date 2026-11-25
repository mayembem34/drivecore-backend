<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MilestoneTenTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function workspace(string $role = 'company-admin'): array
    {
        $this->freezeTime();
        $this->seed(RolePermissionSeeder::class);
        $c = Company::factory()->create();
        $b = Branch::factory()->create(['company_id' => $c->id]);
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->companies()->attach($c->id);
        Sanctum::actingAs($u);

        return [$u, $c, $b];
    }

    public function test_driver_channels_viewer_send_and_read_scope(): void
    {
        [$u,$c,$b] = $this->workspace('driver');
        $body = ['company_id' => $c->id, 'branch_id' => $b->id, 'channel' => 'drivers', 'body' => 'Ready for dispatch'];
        $id = $this->postJson('/api/v1/messages', $body)->assertCreated()->assertJsonPath('data.sender_name', $u->name)->json('data.id');
        $this->getJson('/api/v1/messages?company_id='.$c->id)->assertOk()->assertJsonPath('meta.unread_count', 1)->assertJsonPath('data.0.is_read', false);
        $this->postJson('/api/v1/messages/'.$id.'/read')->assertOk();
        $this->postJson('/api/v1/messages/'.$id.'/read')->assertOk();
        $this->getJson('/api/v1/messages?company_id='.$c->id)->assertJsonPath('meta.unread_count', 0);
        foreach (['customers', 'support'] as $channel) {
            $this->postJson('/api/v1/messages', array_replace($body, ['channel' => $channel]))->assertForbidden();
            $this->getJson('/api/v1/messages?company_id='.$c->id.'&channel='.$channel)->assertForbidden();
        }
        $u->syncRoles('viewer');
        $this->postJson('/api/v1/messages', $body)->assertForbidden();
        $foreign = Message::create(['company_id' => Company::factory()->create()->id, 'sender_user_id' => $u->id, 'channel' => 'drivers', 'body' => 'Private']);
        $this->postJson('/api/v1/messages/'.$foreign->id.'/read')->assertNotFound();
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseCount('message_reads', 1);
    }

    public function test_notifications_are_permission_scoped_and_broadcast_read_state_is_per_user(): void
    {
        [$u,$c,$b] = $this->workspace('operations');
        $base = ['company_id' => $c->id, 'branch_id' => $b->id, 'title' => 'Alert', 'body' => 'Action needed'];
        $broadcast = UserNotification::create($base);
        UserNotification::create($base + ['required_permission' => 'invoices.view']);
        UserNotification::create($base + ['user_id' => User::factory()->create()->id]);
        $otherBranch = Branch::factory()->create(['company_id' => $c->id]);
        UserNotification::create(array_replace($base, ['branch_id' => $otherBranch->id]));
        $u->companies()->updateExistingPivot($c->id, ['branch_id' => $b->id]);
        $this->getJson('/api/v1/notifications?company_id='.$c->id)->assertOk()->assertJsonPath('meta.unread_count', 1)->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/notifications/'.$broadcast->id.'/read')->assertOk();
        $this->getJson('/api/v1/notifications?company_id='.$c->id)->assertJsonPath('meta.unread_count', 0);
        $second = User::factory()->create();
        $second->assignRole('operations');
        $second->companies()->attach($c->id, ['branch_id' => $b->id]);
        Sanctum::actingAs($second);
        $this->getJson('/api/v1/notifications?company_id='.$c->id)->assertJsonPath('meta.unread_count', 1);
        $this->postJson('/api/v1/notifications/read-all', ['company_id' => $c->id])->assertOk();
        $this->getJson('/api/v1/notifications?company_id='.$c->id.'&unread_only=1')->assertJsonPath('meta.unread_count', 0)->assertJsonCount(0, 'data');
        $this->assertNull($broadcast->fresh()->read_at);
    }

    public function test_dashboard_finance_reports_and_audit_permissions(): void
    {
        [$u,$c,$b] = $this->workspace();
        Vehicle::factory()->create(['company_id' => $c->id, 'branch_id' => $b->id, 'status' => 'rented']);
        Vehicle::factory()->create(['company_id' => $c->id, 'branch_id' => $b->id, 'status' => 'available']);
        Vehicle::factory()->create();
        $i = Invoice::create(['company_id' => $c->id, 'branch_id' => $b->id, 'created_by' => $u->id, 'custom_customer_name' => 'Client', 'invoice_number' => 'REPORT', 'status' => 'issued', 'due_at' => now()->subDay(), 'issued_at' => now()->subMonth(), 'total_minor' => 1200, 'vat_minor' => 200]);
        Payment::create(['company_id' => $c->id, 'branch_id' => $b->id, 'invoice_id' => $i->id, 'recorded_by' => $u->id, 'amount_minor' => 400, 'method' => 'cash', 'reference' => 'receipt', 'paid_at' => now()]);
        $this->getJson('/api/v1/dashboard/summary?company_id='.$c->id)->assertOk()->assertJsonPath('data.fleet.total', 2)->assertJsonPath('data.fleet.utilization_pct', 50)->assertJsonPath('data.finance.outstanding_ar_minor', 800)->assertJsonPath('data.approvals.overdue_invoices', 1);
        $this->getJson('/api/v1/reports/financial?company_id='.$c->id.'&from='.now()->toDateString())->assertOk()->assertJsonPath('data.summary.invoiced_minor', 0)->assertJsonPath('data.summary.receipts_minor', 400)->assertJsonPath('data.summary.outstanding_ar_minor', 800);
        $this->getJson('/api/v1/audit-events?company_id='.$c->id)->assertOk();
        foreach (['operations', 'finance', 'viewer', 'driver'] as $role) {
            $u->syncRoles($role);
            $this->getJson('/api/v1/audit-events?company_id='.$c->id)->assertForbidden();
        }
        $u->syncRoles('operations');
        $this->getJson('/api/v1/dashboard/summary?company_id='.$c->id)->assertOk()->assertJsonPath('data.finance', null);
        $this->getJson('/api/v1/reports/financial?company_id='.$c->id)->assertForbidden();
        $this->getJson('/api/v1/reports/fleet?company_id='.$c->id)->assertOk()->assertJsonPath('data.rows.0.revenue_minor', null);
        $u->syncRoles('driver');
        $this->getJson('/api/v1/reports/fleet?company_id='.$c->id)->assertForbidden();
        $this->getJson('/api/v1/dashboard/summary?company_id='.$c->id)->assertOk()->assertJsonPath('data.fleet', null)->assertJsonPath('data.driver_summary.active_rentals', 0);
    }
}
