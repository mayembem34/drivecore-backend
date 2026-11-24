<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MilestoneSevenTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function setupQuote(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $company = Company::factory()->create();
        $branch = Branch::factory()->create(['company_id' => $company->id]);
        $user = User::factory()->create();
        $user->assignRole('operations');
        $user->companies()->attach($company->id);
        Sanctum::actingAs($user);
        $customer = Customer::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id]);
        $vehicles = Vehicle::factory()->count(2)->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'status' => 'available', 'daily_rate_minor' => 10000]);

        return [$user, $company, $branch, ['company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_ids' => $vehicles->pluck('id')->all(), 'starts_at' => '2027-01-01T09:00:00Z', 'ends_at' => '2027-01-04T09:00:00Z', 'discount_pct' => 12.5]];
    }

    public function test_server_pricing_requires_approval_before_atomic_conversion(): void
    {
        [$user,$company,$branch,$payload] = $this->setupQuote();
        $id = $this->postJson('/api/v1/quotations', $payload)->assertCreated()->assertJsonPath('data.rental_days', 3)->assertJsonPath('data.subtotal_minor', 60000)->assertJsonPath('data.discount_minor', 7500)->assertJsonPath('data.vat_minor', 7875)->assertJsonPath('data.total_minor', 60375)->assertJsonPath('data.status', 'pending_approval')->json('data.id');
        $this->postJson('/api/v1/quotations/'.$id.'/approve-discount')->assertForbidden();
        $this->postJson('/api/v1/quotations/'.$id.'/convert')->assertConflict();
        $finance = User::factory()->create();
        $finance->assignRole('finance');
        $finance->companies()->attach($company->id);
        Sanctum::actingAs($finance);
        $this->postJson('/api/v1/quotations/'.$id.'/approve-discount')->assertOk()->assertJsonPath('data.status', 'approved');
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/quotations/'.$id.'/convert')->assertOk()->assertJsonCount(2, 'data.reservation_ids');
        $this->assertDatabaseCount('reservations', 2);
        $this->postJson('/api/v1/quotations/'.$id.'/convert')->assertConflict();
    }

    public function test_conversion_conflict_rolls_back_all_vehicle_reservations(): void
    {
        [$user,$company,$branch,$payload] = $this->setupQuote();
        $payload['discount_pct'] = 0;
        $id = $this->postJson('/api/v1/quotations', $payload)->assertCreated()->json('data.id');
        Reservation::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $payload['customer_id'], 'vehicle_id' => $payload['vehicle_ids'][1], 'created_by' => $user->id, 'starts_at' => $payload['starts_at'], 'ends_at' => $payload['ends_at'], 'status' => 'confirmed']);
        $this->postJson('/api/v1/quotations/'.$id.'/convert')->assertConflict();
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseHas('quotations', ['id' => $id, 'status' => 'approved']);
    }

    public function test_settings_are_whole_company_admin_only_and_do_not_reprice_quotes(): void
    {
        [$user,$company,$branch,$payload] = $this->setupQuote();
        $id = $this->postJson('/api/v1/quotations', $payload)->assertCreated()->json('data.id');
        $settings = ['vat_rate_pct' => 20, 'discount_approval_threshold_pct' => 25];
        $this->patchJson('/api/v1/companies/'.$company->id.'/settings', $settings)->assertForbidden();
        $user->syncRoles('company-admin');
        $this->patchJson('/api/v1/companies/'.$company->id.'/settings', $settings)->assertOk()->assertJsonPath('data.vat_rate_pct', 20);
        $this->assertSame(60375, Quotation::findOrFail($id)->total_minor);
        $this->patchJson('/api/v1/companies/'.$company->id.'/settings', [...$settings, 'vat_rate_pct' => 101])->assertUnprocessable();
        $user->companies()->updateExistingPivot($company->id, ['branch_id' => $branch->id]);
        $this->patchJson('/api/v1/companies/'.$company->id.'/settings', $settings)->assertForbidden();
    }

    public function test_pdf_bytes_and_headers_are_private_and_permissions_are_checked(): void
    {
        [$user,$company,$branch,$payload] = $this->setupQuote();
        $id = $this->postJson('/api/v1/quotations', $payload)->assertCreated()->json('data.id');
        $response = $this->get('/api/v1/quotations/'.$id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('/api/v1/quotations/'.$id.'/pdf?download=1')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="quotations-'.$id.'.pdf"');
        $user->syncRoles('driver');
        $this->get('/api/v1/quotations/'.$id.'/pdf')->assertForbidden();
    }

    public function test_quotes_validate_price_and_isolate_tenants(): void
    {
        [$user,$company,$branch,$payload] = $this->setupQuote();
        Vehicle::findOrFail($payload['vehicle_ids'][0])->update(['daily_rate_minor' => 0]);
        $this->postJson('/api/v1/quotations', $payload)->assertUnprocessable();
        Vehicle::findOrFail($payload['vehicle_ids'][0])->update(['daily_rate_minor' => 100]);
        $id = $this->postJson('/api/v1/quotations', $payload)->assertCreated()->json('data.id');
        $other = User::factory()->create();
        $other->assignRole('company-admin');
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/quotations/'.$id)->assertNotFound();
        $this->postJson('/api/v1/quotations/'.$id.'/convert')->assertNotFound();
    }

    public function test_rejected_and_cancelled_quotes_cannot_convert(): void
    {
        [$user, $company, $branch, $payload] = $this->setupQuote();
        $first = $this->postJson('/api/v1/quotations', $payload)->assertCreated()->json('data.id');
        $user->syncRoles('company-admin');
        $this->postJson('/api/v1/quotations/'.$first.'/reject-discount', ['notes' => 'Discount declined'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson('/api/v1/quotations/'.$first.'/convert')->assertConflict();
        $this->postJson('/api/v1/quotations/'.$first.'/approve-discount')->assertConflict();
        $second = $this->postJson('/api/v1/quotations', [...$payload, 'discount_pct' => 0])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/quotations/'.$second.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson('/api/v1/quotations/'.$second.'/convert')->assertConflict();
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_sorted_quote_pagination_and_invalid_sort(): void
    {
        [$user, $company, $branch, $payload] = $this->setupQuote();
        $id = $this->postJson('/api/v1/quotations', $payload)->assertCreated()->json('data.id');
        $quote = Quotation::findOrFail($id);
        for ($i = 0; $i < 26; $i++) {
            $copy = $quote->replicate();
            $copy->save();
        }
        $this->getJson('/api/v1/quotations?company_id='.$company->id.'&sort=total_minor&dir=desc&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 27)->assertJsonPath('data.1.id', $id);
        $this->getJson('/api/v1/quotations?company_id='.$company->id.'&sort=unknown')->assertUnprocessable();
    }
}
