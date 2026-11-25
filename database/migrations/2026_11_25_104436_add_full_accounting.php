<?php

use App\Actions\PostJournal;
use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_number', 64);
            $table->date('invoice_date');
            $table->date('due_at');
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('vat_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->string('expense_account_code', 20)->nullable();
            $table->string('match_status', 16)->default('non_po'); // matched | variance | non_po
            $table->bigInteger('variance_minor')->default(0);
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests')->nullOnDelete();
            $table->string('status', 20)->default('approved'); // draft | pending_approval | approved | partially_paid | paid | void
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'supplier_id', 'invoice_number']);
        });
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('bank_name', 120);
            $table->string('account_number_masked', 32);
            $table->string('currency', 8)->default('ZMW');
            $table->string('gl_account_code', 20)->default('1000');
            $table->bigInteger('opening_balance_minor')->default(0);
            $table->timestampTz('last_reconciled_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('bank_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->date('statement_date');
            $table->bigInteger('statement_closing_balance_minor');
            $table->bigInteger('reconciled_balance_minor');
            $table->string('status', 16)->default('closed'); // open | closed
            $table->timestampTz('closed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('supplier_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('method', 24); // bank_transfer | cash | card | mobile_money
            $table->string('reference', 80)->nullable();
            $table->date('paid_at');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('credit_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('credit_note_number', 48);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('vat_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->text('reason');
            $table->string('status', 20)->default('posted'); // pending_approval | posted | rejected
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('bank_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->date('transaction_date');
            $table->string('description', 200);
            $table->string('reference', 80)->nullable();
            $table->bigInteger('amount_minor'); // signed: +deposit, -withdrawal
            $table->string('match_status', 16)->default('unmatched'); // unmatched | matched | adjusted
            $table->string('matched_entity_type', 32)->nullable();
            $table->unsignedBigInteger('matched_entity_id')->nullable();
            $table->foreignId('reconciliation_id')->nullable()->constrained('bank_reconciliations')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('fixed_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('asset_code', 40);
            $table->string('name', 140);
            $table->string('category', 32)->default('vehicle'); // vehicle | workshop_equipment | office_equipment | other
            $table->unsignedBigInteger('acquisition_cost_minor');
            $table->unsignedBigInteger('residual_value_minor')->default(0);
            $table->date('capitalisation_date');
            $table->unsignedSmallInteger('useful_life_months');
            $table->string('depreciation_method', 24)->default('straight_line'); // straight_line | reducing_balance
            $table->unsignedBigInteger('accumulated_depreciation_minor')->default(0);
            $table->string('status', 20)->default('active'); // active | pending_disposal | disposed
            $table->string('disposal_type', 20)->nullable(); // sold | decommissioned
            $table->date('disposal_date')->nullable();
            $table->unsignedBigInteger('disposal_proceeds_minor')->nullable();
            $table->bigInteger('disposal_gain_loss_minor')->nullable();
            $table->foreignId('disposal_approval_id')->nullable()->constrained('approval_requests')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('depreciation_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained()->cascadeOnDelete();
            $table->string('period_month', 7); // YYYY-MM
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('posted_at');
            $table->timestamps();
            $table->unique(['fixed_asset_id', 'period_month']);
        });
        Company::query()->orderBy('id')->eachById(fn ($company) => PostJournal::seed($company->id));
    }

    public function down(): void
    {
        foreach (['depreciation_entries', 'fixed_assets', 'bank_transactions', 'credit_notes', 'supplier_payments', 'bank_reconciliations', 'bank_accounts', 'supplier_invoices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
