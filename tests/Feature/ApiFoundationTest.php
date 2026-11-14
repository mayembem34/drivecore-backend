<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Policies\RecordPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function account(string $role = 'company-admin', bool $branchOnly = false): array
    {
        $this->seed(RolePermissionSeeder::class);
        $company = Company::create(['name' => 'DriveCore', 'currency' => 'ZMW']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Main']);
        $user = User::factory()->create();
        $user->companies()->attach($company->id, ['branch_id' => $branchOnly ? $branch->id : null]);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return [$user, $company, $branch];
    }

    private function records(Company $company, Branch $branch): array
    {
        return [Customer::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Customer']), Vehicle::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'registration' => 'ABC-'.$branch->id, 'make' => 'Toyota', 'model' => 'Corolla'])];
    }

    public function test_health_is_public_and_protected_endpoints_return_json_401(): void
    {
        $this->get('/api/v1/health')->assertOk()->assertJsonPath('data.status', 'up');
        $this->get('/api/v1/customers')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_mobile_login_issues_expiring_token_and_logout_revokes_it(): void
    {
        $user = User::factory()->create(['email' => 'driver@example.com', 'password' => Hash::make('Testing123456')]);
        $token = $this->postJson('/api/v1/auth/tokens', ['email' => $user->email, 'password' => 'Testing123456', 'device_name' => 'Android'])->assertOk()->json('data.token');
        $this->assertNotNull($user->tokens()->first()->expires_at);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', $user->email)->assertJsonMissingPath('data.password');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_invalid_and_disabled_credentials_are_rejected(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->postJson('/api/v1/auth/tokens', ['email' => $user->email, 'password' => 'password', 'device_name' => 'Android'])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_session_login_rotates_session_and_returns_profile(): void
    {
        $user = User::factory()->create(['password' => 'Testing123456']);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Testing123456'])->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_company_and_branch_data_cannot_be_read_or_written_across_boundaries(): void
    {
        [$user,$company,$branch] = $this->account(branchOnly: true);
        $otherBranch = Branch::create(['company_id' => $company->id, 'name' => 'Other']);
        [$own] = $this->records($company, $branch);
        [$other] = $this->records($company, $otherBranch);
        $this->getJson('/api/v1/customers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson('/api/v1/customers/'.$other->id)->assertNotFound();
        $this->patchJson('/api/v1/customers/'.$other->id, ['name' => 'Changed', 'type' => 'individual'])->assertNotFound();
        $this->postJson('/api/v1/customers', ['company_id' => $company->id, 'branch_id' => $otherBranch->id, 'name' => 'New', 'type' => 'individual'])->assertNotFound();
        $this->getJson('/api/v1/branches')->assertOk()->assertJsonCount(1, 'data');
        $otherCompany = Company::create(['name' => 'Other company', 'currency' => 'EUR']);
        $otherCompanyBranch = Branch::create(['company_id' => $otherCompany->id, 'name' => 'Other company branch']);
        [$foreign] = $this->records($otherCompany, $otherCompanyBranch);
        $this->getJson('/api/v1/customers/'.$foreign->id)->assertNotFound();
    }

    public function test_viewer_and_driver_cannot_write_or_escalate_privileges(): void
    {
        [$user,$company,$branch] = $this->account('viewer');
        $this->postJson('/api/v1/vehicles', [])->assertForbidden();
        $this->postJson('/api/v1/users', [])->assertForbidden();
        $user->syncRoles('driver');
        $this->getJson('/api/v1/customers')->assertForbidden();
    }

    public function test_disabled_authenticated_user_is_rejected(): void
    {
        [$user] = $this->account();
        $user->is_active = false;
        $user->save();
        $this->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_customer_validation_and_persistence_ignore_privileged_extra_fields(): void
    {
        [$user,$company,$branch] = $this->account();
        $this->postJson('/api/v1/customers', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'type', 'company_id', 'branch_id']);
        $response = $this->postJson('/api/v1/customers', ['name' => 'Acme', 'type' => 'corporate', 'company_id' => $company->id, 'branch_id' => $branch->id, 'is_platform_admin' => true])->assertCreated()->assertJsonPath('data.name', 'Acme');
        $this->assertDatabaseHas('customers', ['id' => $response->json('data.id'), 'company_id' => $company->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'customer.created', 'user_id' => $user->id]);
    }

    public function test_company_admin_can_create_branch_scoped_user_but_not_platform_admin(): void
    {
        [$user,$company,$branch] = $this->account();
        $response = $this->postJson('/api/v1/users', ['name' => 'Operator', 'email' => 'operator@example.com', 'password' => 'Testing123456', 'password_confirmation' => 'Testing123456', 'company_id' => $company->id, 'branch_id' => $branch->id, 'role' => 'operations', 'is_platform_admin' => true])->assertCreated();
        $created = User::findOrFail($response->json('data.id'));
        $this->assertFalse($created->is_platform_admin);
        $this->assertSame($branch->id, (int) $created->companies()->first()->pivot->branch_id);
        $this->assertTrue($created->hasRole('operations'));
    }

    public function test_overlapping_reservations_are_rejected_and_adjacent_periods_are_allowed(): void
    {
        $this->freezeTime();
        [$user,$company,$branch] = $this->account();
        [$customer,$vehicle] = $this->records($company, $branch);
        $payload = ['company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addHours(2)->toIso8601String()];
        $this->postJson('/api/v1/reservations', $payload)->assertCreated();
        $this->postJson('/api/v1/reservations', $payload)->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
        $this->postJson('/api/v1/reservations', [...$payload, 'starts_at' => $payload['ends_at'], 'ends_at' => now()->addHours(3)->toIso8601String()])->assertCreated();
        $this->assertDatabaseCount('reservations', 2);
    }

    public function test_unavailable_vehicle_and_wrong_company_references_are_rejected(): void
    {
        $this->freezeTime();
        [$user,$company,$branch] = $this->account();
        [$customer,$vehicle] = $this->records($company, $branch);
        $vehicle->update(['status' => 'maintenance']);
        $payload = ['company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addHours(2)->toIso8601String()];
        $this->postJson('/api/v1/reservations', $payload)->assertUnprocessable();
        $vehicle->update(['status' => 'available']);
        $foreign = Company::create(['name' => 'Foreign', 'currency' => 'EUR']);
        $foreignBranch = Branch::create(['company_id' => $foreign->id, 'name' => 'Foreign']);
        [$foreignCustomer] = $this->records($foreign, $foreignBranch);
        $this->postJson('/api/v1/reservations', [...$payload, 'customer_id' => $foreignCustomer->id])->assertNotFound();
    }

    public function test_reservation_rental_handover_and_return_have_controlled_transitions(): void
    {
        $this->freezeTime();
        [$user,$company,$branch] = $this->account();
        [$customer,$vehicle] = $this->records($company, $branch);
        $reservation = Reservation::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'created_by' => $user->id, 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay()]);
        $rentalId = $this->postJson('/api/v1/rentals', ['reservation_id' => $reservation->id])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/rentals', ['reservation_id' => $reservation->id])->assertConflict();
        $this->postJson('/api/v1/reservations/'.$reservation->id.'/cancel')->assertConflict();
        $this->postJson('/api/v1/rentals/'.$rentalId.'/return', ['odometer' => 10, 'notes' => 'Checked', 'vehicle_status' => 'available'])->assertConflict();
        $this->postJson('/api/v1/rentals/'.$rentalId.'/handover', ['odometer' => 20, 'notes' => 'Inspection completed'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'status' => 'rented']);
        $this->postJson('/api/v1/rentals/'.$rentalId.'/handover', ['odometer' => 20, 'notes' => 'Duplicate'])->assertConflict();
        $this->postJson('/api/v1/rentals/'.$rentalId.'/return', ['odometer' => 19, 'notes' => 'Checked', 'vehicle_status' => 'available'])->assertUnprocessable();
        $this->postJson('/api/v1/rentals/'.$rentalId.'/return', ['odometer' => 120, 'notes' => 'Damage inspected', 'vehicle_status' => 'damaged'])->assertOk()->assertJsonPath('data.status', 'returned');
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'status' => 'damaged', 'odometer' => 120]);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'completed']);
    }

    public function test_documents_are_private_and_cross_company_download_is_denied(): void
    {
        Storage::fake('local');
        [$user,$company,$branch] = $this->account();
        $id = $this->postJson('/api/v1/documents', ['company_id' => $company->id, 'branch_id' => $branch->id, 'file' => UploadedFile::fake()->image('inspection.jpg')])->assertCreated()->assertJsonMissingPath('data.path')->json('data.id');
        Storage::disk('local')->assertExists(Document::find($id)->path);
        $this->get('/api/v1/documents/'.$id)->assertOk()->assertDownload();
        $user->companies()->detach();
        $this->get('/api/v1/documents/'.$id)->assertNotFound();
    }

    public function test_document_executable_upload_is_rejected(): void
    {
        Storage::fake('local');
        [$user,$company,$branch] = $this->account();
        $this->postJson('/api/v1/documents', ['company_id' => $company->id, 'branch_id' => $branch->id, 'file' => UploadedFile::fake()->create('shell.php', 1, 'text/x-php')])->assertUnprocessable()->assertJsonValidationErrors('file');
        Storage::disk('local')->assertDirectoryEmpty('');
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/tokens', ['email' => 'limit@example.com', 'password' => 'bad', 'device_name' => 'test'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/tokens', ['email' => 'limit@example.com', 'password' => 'bad', 'device_name' => 'test'])->assertTooManyRequests();
    }

    public function test_authentication_rejects_http_when_https_is_required(): void
    {
        config(['drivecore.require_https' => true]);
        $this->postJson('/api/v1/auth/tokens', ['email' => 'http@example.com', 'password' => 'secret', 'device_name' => 'test'])->assertStatus(426);
    }

    public function test_record_policy_permission_matrix_is_enforced(): void
    {
        [$user, $company, $branch] = $this->account();
        [$customer, $vehicle] = $this->records($company, $branch);
        $policy = new RecordPolicy;
        foreach (['company-admin', 'operations', 'viewer', 'driver'] as $role) {
            $user->syncRoles($role);
            foreach ([$branch, $customer, $vehicle] as $record) {
                $domain = Str::plural(Str::snake(class_basename($record)));
                $view = $role === 'company-admin' || $role === 'viewer' || ($role === 'operations' && $domain !== 'branches');
                $manage = $role === 'company-admin' || ($role === 'operations' && $domain !== 'branches');
                $this->assertSame($view, $policy->view($user, $record), $role.' '.$domain.' view');
                $this->assertSame($manage, $policy->update($user, $record), $role.' '.$domain.' manage');
            }
        }
    }

    public function test_horizon_requires_an_active_platform_administrator(): void
    {
        [$user] = $this->account();
        $this->assertFalse(Gate::forUser($user)->allows('viewHorizon'));
        $user->is_platform_admin = true;
        $this->assertTrue(Gate::forUser($user)->allows('viewHorizon'));
        $user->is_active = false;
        $this->assertFalse(Gate::forUser($user)->allows('viewHorizon'));
    }

    public function test_private_s3_document_is_downloaded_through_authorized_endpoint(): void
    {
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);
        [$user, $company, $branch] = $this->account();
        $id = $this->postJson('/api/v1/documents', ['company_id' => $company->id, 'branch_id' => $branch->id, 'file' => UploadedFile::fake()->image('photo.png')])->assertCreated()->json('data.id');
        $document = Document::findOrFail($id);
        $this->assertSame('s3', $document->disk);
        Storage::disk('s3')->assertExists($document->path);
        $this->get('/api/v1/documents/'.$id)->assertOk()->assertDownload();
    }

    public function test_offset_timestamps_cannot_bypass_overlap_detection(): void
    {
        $this->freezeTime();
        [$user, $company, $branch] = $this->account();
        [$customer, $vehicle] = $this->records($company, $branch);
        $payload = ['company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addHours(2)->toIso8601String()];
        $this->postJson('/api/v1/reservations', $payload)->assertCreated();
        $payload['starts_at'] = now()->addHour()->setTimezone('Europe/Prague')->toIso8601String();
        $payload['ends_at'] = now()->addHours(2)->setTimezone('Europe/Prague')->toIso8601String();
        $this->postJson('/api/v1/reservations', $payload)->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
    }

    public function test_worker_check_rejects_missing_heartbeats_and_accepts_fresh_ones(): void
    {
        $this->artisan('drivecore:check --workers')->assertFailed();
        Cache::put('drivecore:scheduler-heartbeat', now()->timestamp, 900);
        Cache::put('drivecore:queue-heartbeat', now()->timestamp, 900);
        $this->artisan('drivecore:check --workers')->assertSuccessful();
    }

    public function test_malformed_login_payload_returns_validation_error_instead_of_server_error(): void
    {
        $this->postJson('/api/v1/auth/tokens', ['email' => ['unexpected'], 'password' => 'test', 'device_name' => 'test'])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_vehicle_registration_is_normalized_and_duplicate_is_rejected(): void
    {
        [$user, $company, $branch] = $this->account();
        $payload = ['company_id' => $company->id, 'branch_id' => $branch->id, 'registration' => 'abc-123', 'make' => 'Toyota', 'model' => 'Corolla'];
        $this->postJson('/api/v1/vehicles', $payload)->assertCreated()->assertJsonPath('data.registration', 'ABC-123')->assertJsonPath('data.status', 'available');
        $this->postJson('/api/v1/vehicles', [...$payload, 'registration' => 'ABC-123'])->assertUnprocessable()->assertJsonValidationErrors('registration');
    }
}
