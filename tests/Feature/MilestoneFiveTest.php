<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MilestoneFiveTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function workspace(bool $branchOnly = false, string $role = 'company-admin'): array
    {
        $this->seed(RolePermissionSeeder::class);
        $company = Company::factory()->create();
        $branch = Branch::factory()->create(['company_id' => $company->id]);
        $actor = User::factory()->create();
        $actor->companies()->attach($company->id, ['branch_id' => $branchOnly ? $branch->id : null]);
        $actor->assignRole($role);
        Sanctum::actingAs($actor);

        return [$actor, $company, $branch];
    }

    public function test_workspace_search_is_grouped_scoped_and_paginated_beyond_25_rows(): void
    {
        [$actor, $company, $branch] = $this->workspace(true);
        $other = Branch::factory()->create(['company_id' => $company->id]);
        Customer::factory()->count(27)->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Needle customer', 'type' => 'corporate']);
        Customer::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Needle individual', 'type' => 'individual']);
        Customer::factory()->create(['company_id' => $company->id, 'branch_id' => $other->id, 'name' => 'Needle hidden']);
        Customer::factory()->create(['name' => 'Needle foreign']);
        $url = '/api/v1/customers?company_id='.$company->id.'&q=needle&type=corporate';
        $this->getJson($url)->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 27);
        $this->getJson($url.'&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 27);
        $this->getJson($url.'&branch_id='.$other->id)->assertNotFound();
        $this->getJson('/api/v1/customers')->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->getJson($url.'&page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_vehicle_search_and_status_filters_do_not_cross_company_or_branch(): void
    {
        [$actor, $company, $branch] = $this->workspace();
        $other = Branch::factory()->create(['company_id' => $company->id]);
        Vehicle::factory()->count(26)->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'make' => 'Toyota', 'status' => 'maintenance']);
        Vehicle::factory()->create(['company_id' => $company->id, 'branch_id' => $other->id, 'make' => 'Toyota', 'status' => 'maintenance']);
        Vehicle::factory()->create(['make' => 'Toyota', 'status' => 'maintenance']);
        $url = '/api/v1/vehicles?company_id='.$company->id.'&branch_id='.$branch->id.'&q=TOYOTA&status=maintenance';
        $this->getJson($url.'&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 26);
        $foreign = Company::factory()->create();
        $this->getJson('/api/v1/vehicles?company_id='.$foreign->id)->assertNotFound();
        $this->getJson($url.'&status=invalid')->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_search_metacharacters_are_literal_and_branch_directory_remains_compatible(): void
    {
        [$actor, $company, $branch] = $this->workspace();
        Customer::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => '100%_! Match']);
        Customer::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => '100percent Match']);
        $this->getJson('/api/v1/customers?'.http_build_query(['company_id' => $company->id, 'q' => '%_!']))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/companies?q='.urlencode($company->name))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/branches')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/branches?company_id='.$company->id.'&q='.urlencode($branch->name))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/branches?branch_id='.$branch->id)->assertUnprocessable();
    }

    public function test_vehicle_pricing_functions_and_kilometres_persist_on_create_and_update(): void
    {
        [$actor, $company, $branch] = $this->workspace();
        $payload = ['company_id' => $company->id, 'branch_id' => $branch->id, 'registration' => 'abc-123', 'make' => 'Toyota', 'model' => 'Hilux', 'daily_rate_minor' => 125099, 'functions' => ['gps_tracker', 'awd_4x4'], 'odometer' => 4200, 'odometer_unit' => 'km'];
        $id = $this->postJson('/api/v1/vehicles', $payload)->assertCreated()->assertJsonPath('data.daily_rate_minor', 125099)->assertJsonPath('data.functions', ['gps_tracker', 'awd_4x4'])->assertJsonPath('data.odometer_unit', 'km')->json('data.id');
        $this->patchJson('/api/v1/vehicles/'.$id, [...$payload, 'daily_rate_minor' => 0, 'functions' => []])->assertOk()->assertJsonPath('data.functions', []);
        $this->getJson('/api/v1/vehicles/'.$id)->assertOk()->assertJsonPath('data.daily_rate_minor', 0)->assertJsonPath('data.odometer', 4200);
        $this->assertDatabaseHas('vehicles', ['id' => $id, 'daily_rate_minor' => 0]);
        $this->assertDatabaseHas('audit_events', ['action' => 'vehicle.updated', 'subject_id' => $id]);
        $this->postJson('/api/v1/vehicles', [...$payload, 'daily_rate_minor' => -1, 'functions' => ['unknown'], 'odometer_unit' => 'mi'])->assertUnprocessable()->assertJsonValidationErrors(['daily_rate_minor', 'functions.0', 'odometer_unit']);
        $this->postJson('/api/v1/vehicles', [...$payload, 'functions' => ['automatic', 'automatic']])->assertUnprocessable()->assertJsonValidationErrors('functions.0');
    }

    public function test_existing_vehicle_defaults_are_exposed_without_changing_odometer(): void
    {
        [$actor, $company, $branch] = $this->workspace();
        $vehicle = Vehicle::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'odometer' => 100]);
        $this->getJson('/api/v1/vehicles/'.$vehicle->id)->assertOk()->assertJsonPath('data.daily_rate_minor', 0)->assertJsonPath('data.functions', [])->assertJsonPath('data.odometer_unit', 'km')->assertJsonPath('data.odometer', 100);
    }

    public function test_user_directory_filters_activation_role_search_and_pagination_without_foreign_memberships(): void
    {
        [$actor, $company, $branch] = $this->workspace();
        foreach (User::factory()->count(27)->create(['name' => 'Fleet Driver']) as $user) {
            $user->assignRole('driver');
            $user->companies()->attach($company->id, ['branch_id' => $branch->id]);
        }
        $foreign = Company::factory()->create();
        $shared = $company->users()->where('name', 'Fleet Driver')->first();
        $shared->companies()->attach($foreign->id);
        $this->getJson('/api/v1/users?company_id='.$company->id.'&branch_id='.$branch->id.'&role=driver&is_active=1&q=fleet&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 27)->assertJsonMissingPath('data.0.companies')->assertJsonMissingPath('data.0.password');
        $this->getJson('/api/v1/users?company_id='.$foreign->id)->assertNotFound();
        $this->getJson('/api/v1/users?company_id='.$company->id.'&is_active=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/roles?company_id='.$company->id)->assertOk()->assertJsonCount(4, 'data');
    }

    public function test_admin_can_update_user_membership_role_activation_and_revoke_tokens(): void
    {
        [$actor, $company, $branch] = $this->workspace();
        $user = User::factory()->create();
        $user->companies()->attach($company->id);
        $user->assignRole('viewer');
        $user->createToken('test');
        $this->patchJson('/api/v1/users/'.$user->id, ['company_id' => $company->id, 'role' => 'driver', 'branch_id' => $branch->id, 'is_active' => false, 'is_platform_admin' => true])->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.roles.0', 'driver')->assertJsonPath('data.branch_id', $branch->id);
        $this->assertFalse($user->fresh()->is_platform_admin);
        $this->assertDatabaseHas('company_user', ['company_id' => $company->id, 'user_id' => $user->id, 'branch_id' => $branch->id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_events', ['action' => 'user.updated', 'subject_type' => 'User', 'subject_id' => $user->id, 'company_id' => $company->id]);
        $this->patchJson('/api/v1/users/'.$user->id, ['company_id' => $company->id, 'branch_id' => null, 'is_active' => true])->assertOk()->assertJsonPath('data.branch_id', null);
    }

    public function test_admin_cannot_change_self_foreign_users_or_global_access_of_shared_users(): void
    {
        [$actor, $company, $branch] = $this->workspace();
        $foreign = Company::factory()->create();
        $user = User::factory()->create();
        $user->companies()->attach($foreign->id);
        $this->patchJson('/api/v1/users/'.$user->id, ['company_id' => $company->id, 'is_active' => false])->assertNotFound();
        $user->companies()->attach($company->id);
        $this->patchJson('/api/v1/users/'.$user->id, ['company_id' => $company->id, 'role' => 'company-admin'])->assertConflict();
        $this->patchJson('/api/v1/users/'.$actor->id, ['company_id' => $company->id, 'is_active' => false])->assertConflict();
        $foreignBranch = Branch::factory()->create(['company_id' => $foreign->id]);
        $this->patchJson('/api/v1/users/'.$user->id, ['company_id' => $company->id, 'branch_id' => $foreignBranch->id])->assertNotFound();
        $this->assertTrue($actor->fresh()->is_active);
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_branch_admin_and_driver_cannot_administer_users_or_unassigned_rentals(): void
    {
        [$actor, $company, $branch] = $this->workspace(true);
        $this->getJson('/api/v1/users?company_id='.$company->id)->assertForbidden();
        $actor->syncRoles('driver');
        $this->getJson('/api/v1/companies')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/branches?company_id='.$company->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/rentals')->assertForbidden();
        $this->getJson('/api/v1/users?company_id='.$company->id)->assertForbidden();
        $this->patchJson('/api/v1/users/'.$actor->id, ['company_id' => $company->id, 'role' => 'company-admin'])->assertForbidden();
    }

    public function test_user_management_requires_authentication_and_hides_platform_accounts(): void
    {
        $this->getJson('/api/v1/users?company_id=1')->assertUnauthorized();
        $this->getJson('/api/v1/roles?company_id=1')->assertUnauthorized();
        [$actor, $company, $branch] = $this->workspace();
        $platform = User::factory()->create(['is_platform_admin' => true]);
        $platform->companies()->attach($company->id);
        $this->getJson('/api/v1/users?company_id='.$company->id)->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson('/api/v1/users/'.$platform->id, ['company_id' => $company->id, 'role' => 'driver'])->assertNotFound();
        $this->patchJson('/api/v1/users/'.$actor->id, ['company_id' => $company->id, 'role' => 'platform-admin'])->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_platform_admin_can_manage_global_access_of_a_multi_company_user(): void
    {
        [$actor, $company, $branch] = $this->workspace();
        $actor->is_platform_admin = true;
        $actor->save();
        $other = Company::factory()->create();
        $user = User::factory()->create();
        $user->companies()->attach([$company->id, $other->id]);
        $user->assignRole('viewer');
        $this->patchJson('/api/v1/users/'.$user->id, ['company_id' => $company->id, 'role' => 'driver', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertTrue($user->fresh()->hasRole('driver'));
    }
}
