<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Rental;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MilestoneSixTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function workspace(bool $branchOnly = false): array
    {
        $this->freezeTime();
        $this->travelTo(now()->startOfSecond());
        $this->seed(RolePermissionSeeder::class);
        $company = Company::factory()->create();
        $branch = Branch::factory()->create(['company_id' => $company->id]);
        $user = User::factory()->create();
        $user->assignRole('company-admin');
        $user->companies()->attach($company->id, ['branch_id' => $branchOnly ? $branch->id : null]);
        Sanctum::actingAs($user);

        return [$user, $company, $branch];
    }

    private function rental(User $actor, Company $company, Branch $branch, string $status = 'draft', ?User $driver = null): Rental
    {
        $customer = Customer::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Needle customer']);
        $vehicle = Vehicle::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'status' => $status === 'active' ? 'rented' : 'available', 'odometer' => 100]);
        $reservation = Reservation::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'created_by' => $actor->id, 'starts_at' => now()->addHour(), 'ends_at' => now()->addDay()]);

        return Rental::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'reservation_id' => $reservation->id, 'vehicle_id' => $vehicle->id, 'status' => $status, 'driver_user_id' => $driver?->id, 'handover_odometer' => $status === 'active' ? 100 : null]);
    }

    private function driver(Company $company, ?Branch $branch = null): User
    {
        $driver = User::factory()->create();
        $driver->assignRole('driver');
        $driver->companies()->attach($company->id, ['branch_id' => $branch?->id]);

        return $driver;
    }

    public function test_operation_lists_filter_search_and_paginate_with_company_and_branch_isolation(): void
    {
        [$actor, $company, $branch] = $this->workspace(true);
        for ($i = 0; $i < 27; $i++) {
            $this->rental($actor, $company, $branch);
        }
        $other = Branch::factory()->create(['company_id' => $company->id]);
        $this->rental($actor, $company, $other);
        $foreign = Company::factory()->create();
        $foreignBranch = Branch::factory()->create(['company_id' => $foreign->id]);
        $hidden = $this->rental($actor, $foreign, $foreignBranch);
        $this->getJson('/api/v1/rentals?company_id='.$company->id.'&q=needle&status=draft&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 27)->assertJsonPath('data.0.customer_name', 'Needle customer')->assertJsonPath('data.0.odometer_unit', 'km');
        $this->getJson('/api/v1/reservations?company_id='.$company->id.'&q=needle&status=confirmed&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 27)->assertJsonPath('data.0.has_rental', true);
        $this->getJson('/api/v1/rentals?company_id='.$company->id.'&branch_id='.$other->id)->assertNotFound();
        $this->getJson('/api/v1/reservations?company_id='.$foreign->id)->assertNotFound();
        $this->getJson('/api/v1/rentals/'.$hidden->id)->assertNotFound();
        $this->getJson('/api/v1/rentals')->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->getJson('/api/v1/reservations?company_id='.$company->id.'&vehicle_id=wrong')->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
    }

    public function test_numeric_and_related_operation_filters_are_exact_and_grouped(): void
    {
        [$actor, $company, $branch] = $this->workspace();
        $rental = $this->rental($actor, $company, $branch);
        $this->rental($actor, $company, $branch);
        $url = '/api/v1/reservations?company_id='.$company->id.'&vehicle_id='.$rental->vehicle_id.'&customer_id='.$rental->reservation->customer_id;
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $rental->reservation_id);
        $this->getJson('/api/v1/rentals?company_id='.$company->id.'&vehicle_id='.$rental->vehicle_id.'&status=active&q=needle')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/rentals?company_id='.$company->id.'&q='.urlencode($rental->vehicle->registration))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_extension_updates_end_and_summary_and_rejects_overlap_without_partial_write(): void
    {
        [$actor, $company, $branch] = $this->workspace();
        $rental = $this->rental($actor, $company, $branch, 'active');
        $end = now()->addDays(2)->toIso8601String();
        $this->postJson('/api/v1/rentals/'.$rental->id.'/extend', ['ends_at' => $end, 'notes' => 'Customer requested longer rental'])->assertOk()->assertJsonPath('data.ends_at', now()->addDays(2)->toISOString());
        $this->assertDatabaseHas('audit_events', ['action' => 'rental.extended', 'subject_id' => $rental->id]);
        $this->assertSame('Customer requested longer rental', AuditEvent::where('action', 'rental.extended')->firstOrFail()->metadata['notes']);
        $other = $rental->reservation->replicate();
        $other->starts_at = now()->addDays(3);
        $other->ends_at = now()->addDays(4);
        $other->save();
        $this->postJson('/api/v1/rentals/'.$rental->id.'/extend', ['ends_at' => now()->addDays(3)->toIso8601String()])->assertOk();
        $this->postJson('/api/v1/rentals/'.$rental->id.'/extend', ['ends_at' => now()->addDays(3)->addMinute()->toIso8601String()])->assertConflict();
        $this->assertTrue($rental->reservation->fresh()->ends_at->eq(now()->addDays(3)));
        $this->postJson('/api/v1/rentals/'.$rental->id.'/extend', ['ends_at' => now()->addDays(2)->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->postJson('/api/v1/rentals/'.$rental->id.'/extend', ['ends_at' => '2026-12-20'])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    }

    public function test_draft_and_returned_rentals_cannot_extend_and_returned_cannot_assign(): void
    {
        [$actor, $company, $branch] = $this->workspace();
        $rental = $this->rental($actor, $company, $branch);
        $this->postJson('/api/v1/rentals/'.$rental->id.'/extend', ['ends_at' => now()->addDays(3)->toIso8601String()])->assertConflict();
        $rental->update(['status' => 'returned']);
        $this->postJson('/api/v1/rentals/'.$rental->id.'/extend', ['ends_at' => now()->addDays(3)->toIso8601String()])->assertConflict();
        $this->postJson('/api/v1/rentals/'.$rental->id.'/assign-driver', ['driver_user_id' => null])->assertConflict();
    }

    public function test_early_and_late_handover_preserve_odometer_and_operational_state_rules(): void
    {
        [$actor, $company, $branch] = $this->workspace();
        $early = $this->rental($actor, $company, $branch);
        $this->postJson('/api/v1/rentals/'.$early->id.'/handover', ['odometer' => 99, 'notes' => 'Early'])->assertUnprocessable();
        $this->postJson('/api/v1/rentals/'.$early->id.'/handover', ['odometer' => 101, 'notes' => 'Early confirmed'])->assertOk()->assertJsonPath('data.status', 'active');
        $late = $this->rental($actor, $company, $branch);
        $late->reservation->update(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
        $this->postJson('/api/v1/rentals/'.$late->id.'/handover', ['odometer' => 100, 'notes' => 'Late confirmed'])->assertOk();
        $this->postJson('/api/v1/rentals/'.$late->id.'/handover', ['odometer' => 100, 'notes' => 'Duplicate'])->assertConflict();
        $restricted = $this->rental($actor, $company, $branch);
        $restricted->vehicle->update(['status' => 'maintenance']);
        $this->postJson('/api/v1/rentals/'.$restricted->id.'/handover', ['odometer' => 100, 'notes' => 'Blocked'])->assertConflict();
        $restricted->vehicle->update(['status' => 'available']);
        $restricted->reservation->update(['status' => 'cancelled']);
        $this->postJson('/api/v1/rentals/'.$restricted->id.'/handover', ['odometer' => 100, 'notes' => 'Blocked'])->assertConflict();
    }

    public function test_driver_assignment_creation_unassignment_and_validation(): void
    {
        [$actor, $company, $branch] = $this->workspace();
        $driver = $this->driver($company, $branch);
        $draft = $this->rental($actor, $company, $branch);
        $draft->delete();
        $rentalId = $this->postJson('/api/v1/rentals', ['reservation_id' => $draft->reservation_id, 'driver_user_id' => $driver->id])->assertCreated()->assertJsonPath('data.driver_name', $driver->name)->json('data.id');
        $this->postJson('/api/v1/rentals/'.$rentalId.'/assign-driver', ['driver_user_id' => null])->assertOk()->assertJsonPath('data.driver_user_id', null);
        $this->postJson('/api/v1/rentals/'.$rentalId.'/assign-driver', [])->assertUnprocessable()->assertJsonValidationErrors('driver_user_id');
        $this->postJson('/api/v1/rentals/'.$rentalId.'/assign-driver', ['driver_user_id' => $actor->id])->assertUnprocessable();
        $driver->is_active = false;
        $driver->save();
        $this->postJson('/api/v1/rentals/'.$rentalId.'/assign-driver', ['driver_user_id' => $driver->id])->assertUnprocessable();
        $wrongBranch = Branch::factory()->create(['company_id' => $company->id]);
        $wrong = $this->driver($company, $wrongBranch);
        $this->postJson('/api/v1/rentals/'.$rentalId.'/assign-driver', ['driver_user_id' => $wrong->id])->assertUnprocessable();
        $foreign = Company::factory()->create();
        $wrongCompany = $this->driver($foreign);
        $this->postJson('/api/v1/rentals/'.$rentalId.'/assign-driver', ['driver_user_id' => $wrongCompany->id])->assertUnprocessable();
        $this->postJson('/api/v1/rentals/'.$rentalId.'/assign-driver', ['driver_user_id' => 999999])->assertUnprocessable();
        $this->assertDatabaseHas('audit_events', ['action' => 'rental.driver_assigned', 'subject_id' => $rentalId]);
    }

    public function test_driver_can_only_read_and_transition_assigned_rentals_and_not_reassign_or_extend(): void
    {
        [$actor, $company, $branch] = $this->workspace();
        $driver = $this->driver($company, $branch);
        $own = $this->rental($actor, $company, $branch, driver: $driver);
        $other = $this->rental($actor, $company, $branch);
        Sanctum::actingAs($driver);
        $this->getJson('/api/v1/rentals?company_id='.$company->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson('/api/v1/rentals/'.$other->id)->assertNotFound();
        $this->postJson('/api/v1/rentals/'.$other->id.'/handover', ['odometer' => 100, 'notes' => 'Forbidden'])->assertNotFound();
        $this->postJson('/api/v1/rentals/'.$own->id.'/extend', ['ends_at' => now()->addDays(3)->toIso8601String()])->assertForbidden();
        $this->postJson('/api/v1/rentals/'.$own->id.'/assign-driver', ['driver_user_id' => null])->assertForbidden();
        $this->postJson('/api/v1/rentals', ['reservation_id' => $other->reservation_id])->assertForbidden();
        $this->postJson('/api/v1/rentals/'.$own->id.'/handover', ['odometer' => 100, 'notes' => 'Assigned'])->assertOk();
        $this->postJson('/api/v1/rentals/'.$own->id.'/return', ['odometer' => 110, 'notes' => 'Returned', 'vehicle_status' => 'available'])->assertOk();
        $this->assertDatabaseHas('vehicles', ['id' => $own->vehicle_id, 'odometer' => 110, 'status' => 'available']);
        $this->assertDatabaseHas('reservations', ['id' => $own->reservation_id, 'status' => 'completed']);
        $own->update(['driver_user_id' => null]);
        $this->assertFalse(Gate::forUser($driver)->allows('update', $own->fresh()));
        $this->getJson('/api/v1/rentals/'.$own->id)->assertNotFound();
    }

    public function test_documents_link_targets_cast_multipart_ids_and_stream_privately(): void
    {
        Storage::fake('local');
        [$actor, $company, $branch] = $this->workspace();
        $rental = $this->rental($actor, $company, $branch);
        $data = ['company_id' => (string) $company->id, 'branch_id' => (string) $branch->id, 'entity_type' => 'rental', 'entity_id' => (string) $rental->id, 'expires_at' => now()->addDay()->toIso8601String(), 'file' => UploadedFile::fake()->image('inspection.jpg')];
        $id = $this->postJson('/api/v1/documents', $data)->assertCreated()->assertJsonPath('data.company_id', $company->id)->assertJsonPath('data.entity_id', $rental->id)->assertJsonMissingPath('data.path')->json('data.id');
        $download = $this->get('/api/v1/documents/'.$id)->assertOk()->assertDownload()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        $this->assertNotNull(Document::findOrFail($id)->expires_at);
        $this->assertDatabaseHas('documents', ['id' => $id, 'entity_type' => 'rental', 'entity_id' => $rental->id]);
        Storage::disk('local')->assertExists(Document::findOrFail($id)->path);
        $this->postJson('/api/v1/documents', [...$data, 'entity_id' => 999999])->assertNotFound();
        $this->postJson('/api/v1/documents', [...$data, 'entity_type' => 'invoice'])->assertNotFound();
        $this->postJson('/api/v1/documents', [...$data, 'entity_type' => null])->assertUnprocessable();
        unset($data['entity_type']);
        $this->postJson('/api/v1/documents', $data)->assertUnprocessable()->assertJsonValidationErrors('entity_type');
    }

    public function test_documents_cannot_link_other_company_or_branch_records(): void
    {
        Storage::fake('local');
        [$actor, $company, $branch] = $this->workspace();
        $other = Branch::factory()->create(['company_id' => $company->id]);
        $hidden = $this->rental($actor, $company, $other);
        $this->postJson('/api/v1/documents', ['company_id' => $company->id, 'branch_id' => $branch->id, 'entity_type' => 'rental', 'entity_id' => $hidden->id, 'file' => UploadedFile::fake()->image('inspection.jpg')])->assertNotFound();
        Storage::disk('local')->assertDirectoryEmpty('');
    }

    public function test_driver_document_access_tracks_assignment_and_never_includes_other_entities(): void
    {
        Storage::fake('local');
        [$actor, $company, $branch] = $this->workspace();
        $driver = $this->driver($company, $branch);
        $own = $this->rental($actor, $company, $branch, driver: $driver);
        $other = $this->rental($actor, $company, $branch);
        $payload = ['company_id' => $company->id, 'branch_id' => $branch->id, 'entity_type' => 'rental', 'entity_id' => $own->id, 'file' => UploadedFile::fake()->image('photo.jpg')];
        $otherId = $this->postJson('/api/v1/documents', [...$payload, 'entity_id' => $other->id])->assertCreated()->json('data.id');
        Sanctum::actingAs($driver);
        $ownId = $this->postJson('/api/v1/documents', $payload)->assertCreated()->json('data.id');
        $this->getJson('/api/v1/documents?company_id='.$company->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownId);
        $this->get('/api/v1/documents/'.$ownId)->assertOk();
        $this->get('/api/v1/documents/'.$otherId)->assertNotFound();
        $this->postJson('/api/v1/documents', [...$payload, 'entity_id' => $other->id])->assertNotFound();
        $this->postJson('/api/v1/documents', [...$payload, 'entity_type' => 'vehicle', 'entity_id' => $own->vehicle_id])->assertForbidden();
        $this->postJson('/api/v1/documents', ['company_id' => $company->id, 'branch_id' => $branch->id, 'file' => UploadedFile::fake()->image('unlinked.jpg')])->assertForbidden();
        $own->update(['driver_user_id' => null]);
        $this->get('/api/v1/documents/'.$ownId)->assertNotFound();
    }

    public function test_document_lists_search_and_paginate_without_leaking_other_branches(): void
    {
        [$actor, $company, $branch] = $this->workspace(true);
        for ($i = 0; $i < 27; $i++) {
            Document::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'uploaded_by' => $actor->id, 'disk' => 'local', 'path' => 'private', 'original_name' => 'Needle.pdf', 'mime_type' => 'application/pdf', 'size' => 100]);
        }
        $this->getJson('/api/v1/documents?company_id='.$company->id.'&q=needle&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 27);
        $this->getJson('/api/v1/documents?company_id='.$company->id.'&entity_id=1')->assertUnprocessable();
        $this->getJson('/api/v1/documents?company_id='.$company->id.'&entity_type=vehicle')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_custom_vehicle_functions_normalize_and_reject_invalid_tags(): void
    {
        [$actor, $company, $branch] = $this->workspace();
        $payload = ['company_id' => $company->id, 'branch_id' => $branch->id, 'registration' => 'CUSTOM', 'make' => 'Toyota', 'model' => 'Hilux', 'functions' => ['  Roof   Rack  ', 'VIP Chauffeur']];
        $id = $this->postJson('/api/v1/vehicles', $payload)->assertCreated()->assertJsonPath('data.functions', ['Roof Rack', 'VIP Chauffeur'])->json('data.id');
        $this->patchJson('/api/v1/vehicles/'.$id, [...$payload, 'functions' => ['  Custom  function ']])->assertOk()->assertJsonPath('data.functions', ['Custom function']);
        foreach ([['Roof Rack', 'roof rack'], ['   '], [str_repeat('x', 51)], array_map(fn ($i) => 'Tag '.$i, range(1, 21))] as $tags) {
            $this->patchJson('/api/v1/vehicles/'.$id, [...$payload, 'functions' => $tags])->assertUnprocessable();
        }
    }

    public function test_viewer_cannot_mutate_rentals_and_unauthenticated_access_stays_protected(): void
    {
        $this->getJson('/api/v1/rentals?company_id=1')->assertUnauthorized();
        $this->getJson('/api/v1/documents?company_id=1')->assertUnauthorized();
        [$actor, $company, $branch] = $this->workspace();
        $rental = $this->rental($actor, $company, $branch);
        $actor->syncRoles('viewer');
        $this->postJson('/api/v1/rentals/'.$rental->id.'/handover', ['odometer' => 100, 'notes' => 'Forbidden'])->assertForbidden();
        $this->postJson('/api/v1/documents', [])->assertForbidden();
    }

    public function test_allowlisted_sorting_is_deterministic_and_nulls_are_last(): void
    {
        [$actor, $company, $branch] = $this->workspace();
        $driver = $this->driver($company);
        $first = $this->rental($actor, $company, $branch, driver: $driver);
        $second = $this->rental($actor, $company, $branch);
        $first->vehicle->update(['registration' => 'ZZZ']);
        $second->vehicle->update(['registration' => 'AAA']);
        $this->getJson('/api/v1/rentals?company_id='.$company->id.'&sort=vehicle_registration&dir=asc')->assertOk()->assertJsonPath('data.0.id', $second->id);
        $this->getJson('/api/v1/rentals?company_id='.$company->id.'&sort=driver_name&dir=desc')->assertOk()->assertJsonPath('data.0.id', $first->id)->assertJsonMissingPath('data.0._directory_sort');
        $this->getJson('/api/v1/reservations?company_id='.$company->id.'&sort=customer_name&dir=desc')->assertOk()->assertJsonPath('data.0.id', $second->reservation_id);
        $this->getJson('/api/v1/rentals?company_id='.$company->id.'&sort=customer_name')->assertOk();
        $this->getJson('/api/v1/users?company_id='.$company->id.'&sort=role')->assertOk();
        $this->getJson('/api/v1/vehicles?company_id='.$company->id.'&sort=registration&dir=desc')->assertOk()->assertJsonPath('data.0.id', $first->vehicle_id);
        $this->getJson('/api/v1/customers?company_id='.$company->id.'&sort=email&dir=desc')->assertOk();
        $this->getJson('/api/v1/rentals?company_id='.$company->id.'&sort='.urlencode('id desc; drop table users'))->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('/api/v1/rentals?company_id='.$company->id.'&dir=asc')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('/api/v1/rentals?company_id='.$company->id.'&sort=id&dir=sideways')->assertUnprocessable()->assertJsonValidationErrors('dir');
    }

    public function test_document_date_sort_handles_nullable_expiries_across_pages(): void
    {
        [$actor, $company, $branch] = $this->workspace();
        for ($i = 0; $i < 27; $i++) {
            Document::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'uploaded_by' => $actor->id, 'disk' => 'local', 'path' => 'private', 'original_name' => 'Document.pdf', 'mime_type' => 'application/pdf', 'size' => 100, 'expires_at' => now()->addDays($i + 1)]);
        }
        $undated = Document::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'uploaded_by' => $actor->id, 'disk' => 'local', 'path' => 'private', 'original_name' => 'Undated.pdf', 'mime_type' => 'application/pdf', 'size' => 100]);
        $this->getJson('/api/v1/documents?company_id='.$company->id.'&sort=expires_at&dir=desc&page=2')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.2.id', $undated->id);
    }
}
