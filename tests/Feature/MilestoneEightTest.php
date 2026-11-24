<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MilestoneEightTest extends TestCase
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

    private function draft(Company $company, Branch $branch): int
    {
        return $this->postJson('/api/v1/invoices', ['company_id' => $company->id, 'branch_id' => $branch->id, 'custom_customer_name' => 'Walk-in customer', 'lines' => [['description' => 'Three days', 'quantity' => 3, 'unit_price_minor' => 10000]], 'due_at' => now()->addDays(14)->toISOString()])->assertCreated()->assertJsonPath('data.total_minor', 34500)->json('data.id');
    }

    public function test_invoice_issue_payment_refund_and_balanced_ledger(): void
    {
        [$user,$company,$branch] = $this->workspace();
        $id = $this->draft($company, $branch);
        $this->postJson('/api/v1/invoices/'.$id.'/issue')->assertOk()->assertJsonPath('data.outstanding_minor', 34500);
        $this->postJson('/api/v1/invoices/'.$id.'/issue')->assertConflict();
        $this->patchJson('/api/v1/invoices/'.$id, ['lines' => [['description' => 'Changed', 'quantity' => 1, 'unit_price_minor' => 1]]])->assertConflict();
        $this->postJson('/api/v1/invoices/'.$id.'/payments', ['amount_minor' => 34501, 'method' => 'cash', 'reference' => 'OVER'])->assertUnprocessable();
        $this->assertDatabaseCount('payments', 0);
        $this->postJson('/api/v1/invoices/'.$id.'/payments', ['amount_minor' => 10000, 'method' => 'cash', 'reference' => 'P1'])->assertCreated();
        $this->postJson('/api/v1/invoices/'.$id.'/void')->assertConflict();
        $this->postJson('/api/v1/invoices/'.$id.'/refunds', ['amount_minor' => 10001, 'reason' => 'Over-refund'])->assertUnprocessable();
        $this->postJson('/api/v1/invoices/'.$id.'/refunds', ['amount_minor' => 2000, 'reason' => 'Refund'])->assertCreated();
        $this->getJson('/api/v1/invoices/'.$id)->assertOk()->assertJsonPath('data.paid_minor', 10000)->assertJsonPath('data.refunded_minor', 2000)->assertJsonPath('data.net_paid_minor', 8000)->assertJsonPath('data.outstanding_minor', 24500);
        $this->getJson('/api/v1/gl/accounts?company_id='.$company->id)->assertOk()->assertJsonPath('is_balanced', true)->assertJsonPath('total_debits_minor', 46500);
        $this->assertSame((int) JournalLine::sum('debit_minor'), (int) JournalLine::sum('credit_minor'));
        $this->getJson('/api/v1/gl/journal-entries?company_id='.$company->id.'&source_type=payment')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.lines.0.account_code', '1000');
    }

    public function test_void_reverses_unpaid_invoice_and_pdf_is_private(): void
    {
        [$user,$company,$branch] = $this->workspace();
        $id = $this->draft($company, $branch);
        $this->postJson('/api/v1/invoices/'.$id.'/issue')->assertOk();
        $this->postJson('/api/v1/invoices/'.$id.'/void')->assertOk()->assertJsonPath('data.status', 'void');
        $this->postJson('/api/v1/invoices/'.$id.'/void')->assertConflict();
        $this->getJson('/api/v1/gl/accounts?company_id='.$company->id)->assertOk()->assertJsonPath('is_balanced', true);
        $this->assertSame(69000, (int) JournalLine::sum('debit_minor'));
        $pdf = $this->get('/api/v1/invoices/'.$id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString('no-store', $pdf->headers->get('Cache-Control'));
    }

    public function test_expense_posts_immutable_ledger_and_rejects_cross_branch_vehicle(): void
    {
        [$user,$company,$branch] = $this->workspace();
        $payload = ['company_id' => $company->id, 'branch_id' => $branch->id, 'category' => 'fuel', 'description' => 'Fuel', 'amount_minor' => 5000, 'incurred_at' => now()->toISOString()];
        $id = $this->postJson('/api/v1/expenses', $payload)->assertCreated()->json('data.id');
        $this->assertDatabaseHas('expenses', ['id' => $id, 'amount_minor' => 5000]);
        $this->patchJson('/api/v1/expenses/'.$id, $payload)->assertNotFound();
        $this->getJson('/api/v1/expenses?company_id='.$company->id)->assertOk()->assertJsonCount(1, 'data');
        $other = Branch::factory()->create(['company_id' => $company->id]);
        $vehicle = Vehicle::factory()->create(['company_id' => $company->id, 'branch_id' => $other->id]);
        $this->postJson('/api/v1/expenses', [...$payload, 'vehicle_id' => $vehicle->id])->assertNotFound();
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_finance_access_and_record_isolation(): void
    {
        [$user,$company,$branch] = $this->workspace();
        $id = $this->draft($company, $branch);
        foreach (['viewer', 'operations', 'driver'] as $role) {
            $user->syncRoles($role);
            $this->getJson('/api/v1/gl/accounts?company_id='.$company->id)->assertForbidden();
            $this->getJson('/api/v1/gl/journal-entries?company_id='.$company->id)->assertForbidden();
            $this->postJson('/api/v1/invoices/'.$id.'/issue')->assertForbidden();
        }
        $user->syncRoles('finance');
        $user->companies()->detach();
        $this->getJson('/api/v1/invoices/'.$id)->assertNotFound();
        $this->postJson('/api/v1/invoices/'.$id.'/payments', ['amount_minor' => 1, 'method' => 'cash', 'reference' => 'X'])->assertNotFound();
        $this->get('/api/v1/invoices/'.$id.'/pdf')->assertNotFound();
    }

    public function test_ageing_and_paid_filters_use_live_balances(): void
    {
        [$user,$company,$branch] = $this->workspace();
        foreach ([1, -1, -31, -61, -91] as $days) {
            $id = $this->draft($company, $branch);
            Invoice::findOrFail($id)->update(['due_at' => now()->addDays($days)]);
            $this->postJson('/api/v1/invoices/'.$id.'/issue')->assertOk();
        }
        $r = $this->getJson('/api/v1/invoices/ageing?company_id='.$company->id)->assertOk()->assertJsonPath('data.total_outstanding_minor', 172500)->assertJsonCount(5, 'data.buckets');
        foreach ($r->json('data.buckets') as $bucket) {
            $this->assertSame(1, $bucket['count']);
            $this->assertSame(34500, $bucket['amount_minor']);
        }
        $this->getJson('/api/v1/invoices?company_id='.$company->id.'&ageing_bucket=d31_60')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/invoices/'.$id.'/payments', ['amount_minor' => 34500, 'method' => 'cash', 'reference' => 'FULL'])->assertCreated();
        $this->getJson('/api/v1/invoices?company_id='.$company->id.'&status=paid&sort=outstanding_minor&dir=desc')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/invoices?company_id='.$company->id.'&sort=unknown')->assertUnprocessable();
    }

    public function test_return_creates_one_draft_invoice_and_preserves_quote_pricing(): void
    {
        [$user,$company,$branch] = $this->workspace();
        $customer = Customer::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id]);
        $vehicle = Vehicle::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'status' => 'available', 'daily_rate_minor' => 10000]);
        $quote = $this->postJson('/api/v1/quotations', ['company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_ids' => [$vehicle->id], 'starts_at' => now()->toISOString(), 'ends_at' => now()->addDays(3)->toISOString(), 'discount_pct' => 10])->assertCreated()->json('data.id');
        $reservation = $this->postJson('/api/v1/quotations/'.$quote.'/convert')->assertOk()->json('data.reservation_ids.0');
        $rental = $this->postJson('/api/v1/rentals', ['reservation_id' => $reservation])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/rentals/'.$rental.'/handover', ['odometer' => $vehicle->odometer, 'notes' => 'Handover'])->assertOk();
        $company->update(['vat_rate_pct' => 20]);
        $vehicle->update(['daily_rate_minor' => 99000]);
        $response = $this->postJson('/api/v1/rentals/'.$rental.'/return', ['odometer' => $vehicle->odometer + 100, 'notes' => 'Return', 'vehicle_status' => 'available', 'extra_charge_minor' => 1000, 'extra_charge_description' => 'Cleaning'])->assertOk();
        $invoice = Invoice::findOrFail($response->json('data.invoice_id'));
        $this->assertSame(31000, $invoice->subtotal_minor);
        $this->assertSame(3100, $invoice->discount_minor);
        $this->assertSame(4185, $invoice->vat_minor);
        $this->assertSame(32085, $invoice->total_minor);
        $this->assertSame('draft', $invoice->status);
        $this->assertSame(2, $invoice->lines()->count());
        $this->postJson('/api/v1/rentals/'.$rental.'/return', ['odometer' => $vehicle->odometer + 100, 'notes' => 'Again', 'vehicle_status' => 'available'])->assertConflict();
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_manual_customer_rules_draft_edit_and_zero_issue(): void
    {
        [$user,$company,$branch] = $this->workspace();
        $id = $this->draft($company, $branch);
        $this->patchJson('/api/v1/invoices/'.$id, ['lines' => [['description' => 'Zero', 'quantity' => 1, 'unit_price_minor' => 0]], 'custom_customer_name' => 'Changed'])->assertOk()->assertJsonPath('data.total_minor', 0);
        $this->postJson('/api/v1/invoices/'.$id.'/issue')->assertUnprocessable();
        $this->assertDatabaseCount('journal_entries', 0);
        $this->postJson('/api/v1/invoices', ['company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => 1, 'custom_customer_name' => 'Both', 'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price_minor' => 1]], 'due_at' => now()->toISOString()])->assertUnprocessable();
    }

    public function test_branch_scoped_trial_balance_excludes_other_branch_activity(): void
    {
        [$user, $company, $branch] = $this->workspace();
        $other = Branch::factory()->create(['company_id' => $company->id]);
        foreach ([$branch, $other] as $target) {
            $this->postJson('/api/v1/expenses', ['company_id' => $company->id, 'branch_id' => $target->id, 'category' => 'general', 'description' => 'Office', 'amount_minor' => 300, 'incurred_at' => now()->toISOString()])->assertCreated();
        }
        $user->companies()->updateExistingPivot($company->id, ['branch_id' => $branch->id]);
        $this->getJson('/api/v1/gl/accounts?company_id='.$company->id)->assertOk()->assertJsonPath('total_debits_minor', 300)->assertJsonPath('is_balanced', true);
        $this->getJson('/api/v1/gl/accounts?company_id='.$company->id.'&branch_id='.$other->id)->assertNotFound();
        $this->getJson('/api/v1/gl/journal-entries?company_id='.$company->id)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_invoice_lists_paginate_and_preserve_numeric_sort_order(): void
    {
        [$user, $company, $branch] = $this->workspace();
        $id = $this->draft($company, $branch);
        $record = Invoice::findOrFail($id);
        for ($index = 1; $index <= 26; $index++) {
            $copy = $record->replicate();
            $copy->invoice_number = 'PAGE-'.$index;
            $copy->total_minor = $index;
            $copy->save();
        }
        $this->getJson('/api/v1/invoices?company_id='.$company->id.'&sort=total_minor&dir=asc&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 27)->assertJsonPath('data.1.id', $id);
    }
}
