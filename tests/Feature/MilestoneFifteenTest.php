<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MilestoneFifteenTest extends TestCase
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

    public function test_supplier_bills_matching_variance_and_payments(): void
    {
        [$u,$c,$b] = $this->workspace();
        $s = Supplier::create(['company_id' => $c->id, 'name' => 'Vendor']);
        $po = PurchaseOrder::create(['company_id' => $c->id, 'branch_id' => $b->id, 'supplier_id' => $s->id, 'created_by' => $u->id, 'po_number' => 'MATCH', 'status' => 'received', 'total_minor' => 1000]);
        $body = ['company_id' => $c->id, 'branch_id' => $b->id, 'supplier_id' => $s->id, 'purchase_order_id' => $po->id, 'invoice_number' => 'BILL1', 'invoice_date' => now()->toDateString(), 'due_at' => now()->addDays(10)->toDateString(), 'subtotal_minor' => 1100];
        $response = $this->postJson('/api/v1/ap/invoices', $body)->assertCreated()->assertJsonPath('data.match_status', 'variance');
        $bill = $response->json('data.id');
        $approval = $response->json('data.approval_request_id');
        $pay = ['amount_minor' => 1100, 'method' => 'cash', 'paid_at' => now()->toDateString()];
        $this->postJson('/api/v1/ap/invoices/'.$bill.'/payments', $pay)->assertConflict();
        $second = User::factory()->create();
        $second->assignRole('finance-manager');
        $second->companies()->attach($c->id);
        Sanctum::actingAs($second);
        $this->postJson('/api/v1/approvals/'.$approval.'/approve')->assertOk();
        $this->postJson('/api/v1/ap/invoices/'.$bill.'/payments', $pay)->assertCreated();
        $this->postJson('/api/v1/ap/invoices/'.$bill.'/payments', $pay)->assertConflict();
        $this->assertDatabaseHas('supplier_invoices', ['id' => $bill, 'status' => 'paid', 'paid_minor' => 1100]);
        $this->assertSame((int) JournalLine::sum('debit_minor'), (int) JournalLine::sum('credit_minor'));
    }

    public function test_non_po_bill_ageing_and_credit_notes_reduce_live_ar(): void
    {
        [$u,$c,$b] = $this->workspace();
        $s = Supplier::create(['company_id' => $c->id, 'name' => 'Vendor']);
        $this->postJson('/api/v1/ap/invoices', ['company_id' => $c->id, 'branch_id' => $b->id, 'supplier_id' => $s->id, 'invoice_number' => 'NONPO', 'invoice_date' => now()->subDays(40)->toDateString(), 'due_at' => now()->subDays(35)->toDateString(), 'subtotal_minor' => 1000, 'expense_account_code' => '5000'])->assertCreated()->assertJsonPath('data.match_status', 'non_po');
        $this->getJson('/api/v1/ap/invoices/ageing?company_id='.$c->id)->assertOk()->assertJsonPath('data.buckets.d31_60', 1000);
        $customer = Customer::factory()->create(['company_id' => $c->id, 'branch_id' => $b->id]);
        $i = Invoice::create(['company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customer->id, 'created_by' => $u->id, 'invoice_number' => 'CREDIT', 'status' => 'issued', 'issued_at' => now(), 'due_at' => now(), 'total_minor' => 1200, 'vat_minor' => 200]);
        $this->postJson('/api/v1/invoices/'.$i->id.'/credit-notes', ['total_minor' => 300, 'reason' => 'Adjustment'])->assertCreated()->assertJsonPath('data.vat_minor', 50);
        $this->postJson('/api/v1/invoices/'.$i->id.'/credit-notes', ['total_minor' => 1000, 'reason' => 'Too much'])->assertUnprocessable();
        $this->getJson('/api/v1/dashboard/summary?company_id='.$c->id)->assertOk()->assertJsonPath('data.finance.revenue_minor', 900)->assertJsonPath('data.finance.vat_minor', 150);
        $this->getJson('/api/v1/invoices/'.$i->id)->assertOk()->assertJsonPath('data.outstanding_minor', 900);
        $this->getJson('/api/v1/customers/'.$customer->id.'/statement')->assertOk()->assertJsonPath('data.closing_balance_minor', 900);
        $this->getJson('/api/v1/invoices/ageing?company_id='.$c->id)->assertOk()->assertJsonPath('data.total_outstanding_minor', 900);
    }

    public function test_bank_fee_reconciliation_balances_and_closed_transaction_is_immutable(): void
    {
        [$u,$c,$b] = $this->workspace();
        $id = $this->postJson('/api/v1/bank-accounts', ['company_id' => $c->id, 'branch_id' => $b->id, 'name' => 'Operations', 'bank_name' => 'Bank', 'account_number_masked' => '***123', 'opening_balance_minor' => 10000])->assertCreated()->json('data.id');
        $tx = $this->postJson('/api/v1/bank-accounts/'.$id.'/import', ['transactions' => [['transaction_date' => now()->toDateString(), 'description' => 'Bank fee', 'amount_minor' => -100]]])->assertCreated()->json('data.transaction_ids.0');
        $this->postJson('/api/v1/bank-transactions/'.$tx.'/fee')->assertOk();
        $this->postJson('/api/v1/bank-accounts/'.$id.'/reconcile', ['statement_date' => now()->toDateString(), 'statement_closing_balance_minor' => 10000])->assertUnprocessable();
        $this->postJson('/api/v1/bank-accounts/'.$id.'/reconcile', ['statement_date' => now()->toDateString(), 'statement_closing_balance_minor' => 9900])->assertCreated();
        $this->postJson('/api/v1/bank-transactions/'.$tx.'/unmatch')->assertConflict();
        $this->getJson('/api/v1/reports/statements/balance-sheet?company_id='.$c->id)->assertOk()->assertJsonPath('data.summary.balance_difference_minor', 0);
        $this->getJson('/api/v1/reports/statements/pnl?company_id='.$c->id)->assertOk()->assertJsonPath('data.summary.net_profit_minor', -100);
        $u->syncRoles('operations');
        $this->getJson('/api/v1/reports/statements/pnl?company_id='.$c->id)->assertForbidden();
    }

    public function test_depreciation_is_idempotent_and_disposal_requires_independent_approval(): void
    {
        [$u,$c,$b] = $this->workspace();
        $v = Vehicle::factory()->create(['company_id' => $c->id, 'branch_id' => $b->id, 'status' => 'available']);
        $asset = $this->postJson('/api/v1/fixed-assets', ['company_id' => $c->id, 'branch_id' => $b->id, 'vehicle_id' => $v->id, 'asset_code' => 'CAR1', 'name' => 'Car', 'acquisition_cost_minor' => 120000, 'residual_value_minor' => 0, 'capitalisation_date' => now()->subMonth()->toDateString(), 'useful_life_months' => 12])->assertCreated()->json('data.id');
        $d = ['company_id' => $c->id, 'period_month' => now()->format('Y-m')];
        $this->postJson('/api/v1/fixed-assets/depreciate', $d)->assertOk()->assertJsonPath('data.posted_count', 1)->assertJsonPath('data.total_depreciation_minor', 10000);
        $this->postJson('/api/v1/fixed-assets/depreciate', $d)->assertOk()->assertJsonPath('data.posted_count', 0)->assertJsonPath('data.skipped_count', 1);
        $a = $this->postJson('/api/v1/fixed-assets/'.$asset.'/request-disposal', ['proceeds_minor' => 115000, 'disposal_type' => 'sold', 'reason' => 'Fleet replacement'])->assertOk()->json('data.disposal_approval_id');
        $this->postJson('/api/v1/fixed-assets/'.$asset.'/dispose')->assertConflict();
        $second = User::factory()->create();
        $second->assignRole('finance-manager');
        $second->companies()->attach($c->id);
        Sanctum::actingAs($second);
        $this->postJson('/api/v1/approvals/'.$a.'/approve')->assertOk();
        $this->postJson('/api/v1/fixed-assets/'.$asset.'/dispose')->assertOk()->assertJsonPath('data.disposal_gain_loss_minor', 5000);
        $this->assertSame('sold', $v->fresh()->status);
        $this->assertDatabaseCount('depreciation_entries', 1);
        $this->assertSame((int) JournalLine::sum('debit_minor'), (int) JournalLine::sum('credit_minor'));
    }

    public function test_csv_import_auto_matches_exact_reference_and_prevents_reusing_a_payment(): void
    {
        [$u,$c,$b] = $this->workspace();
        $i = Invoice::create(['company_id' => $c->id, 'branch_id' => $b->id, 'created_by' => $u->id, 'custom_customer_name' => 'Customer', 'invoice_number' => 'BANKMATCH', 'status' => 'issued', 'due_at' => now(), 'total_minor' => 500]);
        $payment = Payment::create(['company_id' => $c->id, 'branch_id' => $b->id, 'invoice_id' => $i->id, 'recorded_by' => $u->id, 'amount_minor' => 500, 'method' => 'bank_transfer', 'reference' => 'REF-500', 'paid_at' => now()]);
        $bank = $this->postJson('/api/v1/bank-accounts', ['company_id' => $c->id, 'branch_id' => $b->id, 'name' => 'Bank', 'bank_name' => 'Bank', 'account_number_masked' => '***456'])->assertCreated()->json('data.id');
        $csv = "transaction_date,description,reference,amount_minor\n".now()->toDateString().",Customer receipt,REF-500,500\n";
        $file = UploadedFile::fake()->createWithContent('statement.csv', $csv);
        $this->post('/api/v1/bank-accounts/'.$bank.'/import', ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.imported_count', 1);
        $this->postJson('/api/v1/bank-accounts/'.$bank.'/auto-match')->assertOk()->assertJsonPath('data.matched_count', 1);
        $second = $this->postJson('/api/v1/bank-accounts/'.$bank.'/transactions', ['transactions' => [['transaction_date' => now()->toDateString(), 'description' => 'Duplicate reference', 'reference' => 'REF-500', 'amount_minor' => 500]]])->assertCreated()->json('data.transaction_ids.0');
        $this->postJson('/api/v1/bank-transactions/'.$second.'/match', ['entity_type' => 'payment', 'entity_id' => $payment->id])->assertConflict();
        $this->assertDatabaseCount('bank_transactions', 2);
        $this->assertSame(1, BankTransaction::where('match_status', 'matched')->count());
    }
}
