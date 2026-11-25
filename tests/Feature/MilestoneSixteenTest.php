<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Support\TwoFactorAuth;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MilestoneSixteenTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function workspace(string $role = 'system-administrator'): array
    {
        $this->freezeTime();
        $this->seed(RolePermissionSeeder::class);
        $c = Company::factory()->create();
        $b = Branch::factory()->create(['company_id' => $c->id]);
        $u = User::factory()->create(['password' => 'LoginStrong123']);
        $u->assignRole($role);
        $u->companies()->attach($c->id);
        Sanctum::actingAs($u);

        return [$u, $c, $b];
    }

    public function test_hr_cannot_assign_privileged_roles_and_mechanic_is_branch_scoped(): void
    {
        [$u,$c,$b] = $this->workspace('hr-admin-manager');
        $this->postJson('/api/v1/users', ['company_id' => $c->id, 'name' => 'Executive', 'email' => 'exec@example.com', 'password' => 'StrongPass123', 'password_confirmation' => 'StrongPass123', 'role' => 'ceo'])->assertForbidden();
        $this->postJson('/api/v1/users/'.$u->id.'/reset-two-factor', ['company_id' => $c->id])->assertForbidden();
        $v = Vehicle::factory()->create(['company_id' => $c->id, 'branch_id' => $b->id]);
        $work = WorkOrder::create(['company_id' => $c->id, 'branch_id' => $b->id, 'vehicle_id' => $v->id, 'created_by' => $u->id, 'title' => 'Repair', 'due_at' => now()]);
        $u->syncRoles('mechanic-technician');
        $u->companies()->updateExistingPivot($c->id, ['branch_id' => $b->id]);
        $this->getJson('/api/v1/work-orders/'.$work->id)->assertOk();
        $this->getJson('/api/v1/work-orders?company_id='.$c->id)->assertOk()->assertJsonCount(1, 'data');
        foreach (['customers', 'leads', 'invoices'] as $module) {
            $this->getJson('/api/v1/'.$module.'?company_id='.$c->id)->assertForbidden();
        } $this->postJson('/api/v1/work-orders', ['company_id' => $c->id])->assertForbidden();
    }

    public function test_lead_conversion_links_existing_customer_and_closes_repeated_conversion(): void
    {
        [$u,$c,$b] = $this->workspace('business-accounts-manager');
        $customer = Customer::factory()->create(['company_id' => $c->id, 'branch_id' => $b->id, 'email' => 'lead@example.com']);
        $v = Vehicle::factory()->create(['company_id' => $c->id, 'branch_id' => $b->id, 'status' => 'available', 'daily_rate_minor' => 100]);
        $lead = $this->postJson('/api/v1/leads', ['company_id' => $c->id, 'branch_id' => $b->id, 'contact_name' => 'Contact', 'email' => $customer->email])->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/leads/'.$lead, ['stage' => 'lost'])->assertUnprocessable();
        $payload = ['vehicle_ids' => [$v->id], 'starts_at' => now()->toISOString(), 'ends_at' => now()->addDay()->toISOString()];
        $this->postJson('/api/v1/leads/'.$lead.'/convert', $payload)->assertOk()->assertJsonPath('data.stage', 'proposal_sent')->assertJsonPath('data.customer_id', $customer->id);
        $this->postJson('/api/v1/leads/'.$lead.'/convert', $payload)->assertConflict();
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('quotations', 1);
    }

    public function test_totp_challenge_and_recovery_codes_are_single_use_for_browser_and_token_login(): void
    {
        [$u,$c,$b] = $this->workspace();
        $this->assertSame('287082', TwoFactorAuth::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59));
        $secret = $this->postJson('/api/v1/auth/two-factor/setup')->assertOk()->json('data.secret');
        $codes = $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => TwoFactorAuth::code($secret)])->assertOk()->assertJsonCount(8, 'data.recovery_codes')->json('data.recovery_codes');
        $this->assertNotSame($secret, $u->fresh()->getRawOriginal('two_factor_secret'));
        $this->assertStringNotContainsString($codes[0], $u->fresh()->getRawOriginal('two_factor_recovery_codes'));
        $this->postJson('/api/v1/auth/logout')->assertOk();
        Auth::forgetGuards();
        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'LoginStrong123'])->assertOk()->assertJsonPath('two_factor_required', true)->assertJsonMissingPath('data.token')->json('challenge_token');
        $this->assertGuest('web');
        $this->postJson('/api/v1/auth/two-factor/challenge', ['challenge_token' => $challenge, 'code' => TwoFactorAuth::code($secret)])->assertOk()->assertJsonPath('data.id', $u->id);
        $this->assertAuthenticatedAs($u, 'web');
        $this->postJson('/api/v1/auth/logout')->assertOk();
        Auth::forgetGuards();
        $challenge = $this->postJson('/api/v1/auth/tokens', ['email' => $u->email, 'password' => 'LoginStrong123', 'device_name' => 'Mobile'])->assertOk()->assertJsonPath('two_factor_required', true)->json('challenge_token');
        $this->postJson('/api/v1/auth/two-factor/challenge', ['challenge_token' => $challenge, 'recovery_code' => $codes[0]])->assertOk()->assertJsonPath('data.token_type', 'Bearer');
        $this->postJson('/api/v1/auth/two-factor/challenge', ['challenge_token' => $challenge, 'recovery_code' => $codes[0]])->assertUnprocessable();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.2']);
        $fresh = $this->postJson('/api/v1/auth/tokens', ['email' => $u->email, 'password' => 'LoginStrong123', 'device_name' => 'Another device'])->assertOk()->json('challenge_token');
        $this->postJson('/api/v1/auth/two-factor/challenge', ['challenge_token' => $fresh, 'recovery_code' => $codes[0]])->assertUnprocessable();
        $this->assertCount(7, $u->fresh()->two_factor_recovery_codes);
    }

    public function test_enforced_enrollment_and_independent_administrator_reset(): void
    {
        [$admin,$c,$b] = $this->workspace();
        $admin->forceFill(['two_factor_secret' => TwoFactorAuth::secret(), 'two_factor_confirmed_at' => now()])->save();
        $this->patchJson('/api/v1/companies/'.$c->id.'/settings', ['require_2fa_for_privileged' => true])->assertOk();
        $target = User::factory()->create();
        $target->assignRole('finance-manager');
        $target->companies()->attach($c->id);
        Sanctum::actingAs($target);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.two_factor_enrollment_required', true);
        $this->getJson('/api/v1/customers?company_id='.$c->id)->assertForbidden();
        $this->postJson('/api/v1/auth/two-factor/disable', ['password' => 'password', 'code' => '123456'])->assertConflict();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/users/'.$target->id.'/reset-two-factor', ['company_id' => $c->id])->assertOk();
        $this->postJson('/api/v1/users/'.$admin->id.'/reset-two-factor', ['company_id' => $c->id])->assertConflict();
        $this->assertDatabaseHas('audit_events', ['subject_id' => $target->id, 'action' => 'security.two_factor_reset']);
    }

    public function test_credit_control_can_place_holds_but_cannot_edit_financial_credit_terms(): void
    {
        [$u,$c,$b] = $this->workspace('credit-control-manager');
        $customer = Customer::factory()->create(['company_id' => $c->id, 'branch_id' => $b->id]);
        $this->patchJson('/api/v1/customers/'.$customer->id.'/credit', ['credit_limit_minor' => 1000])->assertForbidden();
        $this->patchJson('/api/v1/customers/'.$customer->id.'/credit', ['payment_terms_days' => 90])->assertForbidden();
        $this->patchJson('/api/v1/customers/'.$customer->id.'/credit', ['is_on_credit_hold' => true, 'credit_hold_reason' => 'Collection review'])->assertOk()->assertJsonPath('data.is_on_credit_hold', true);
    }
}
