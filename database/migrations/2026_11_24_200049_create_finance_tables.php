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
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('custom_customer_name', 255)->nullable();
            $table->string('custom_customer_email', 255)->nullable();
            $table->string('custom_customer_phone', 50)->nullable();
            $table->foreignId('rental_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('invoice_number', 40);
            $table->string('status', 20)->default('draft'); // draft | issued | void
            $table->timestampTz('due_at');
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->decimal('discount_pct', 5, 2)->default(0.00);
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->decimal('vat_rate_pct', 5, 2)->default(15.00);
            $table->unsignedBigInteger('vat_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'invoice_number']);
            $table->index(['company_id', 'branch_id', 'status']);
            $table->index(['company_id', 'status', 'due_at']);
            $table->index(['company_id', 'rental_id']);
        });

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description', 255);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users');
            $table->unsignedBigInteger('amount_minor');
            $table->string('method', 32); // cash | card | bank_transfer | mobile_money
            $table->string('reference', 120);
            $table->timestampTz('paid_at');
            $table->timestamps();
            $table->index(['company_id', 'branch_id', 'paid_at']);
            $table->index(['invoice_id']);
        });

        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users');
            $table->unsignedBigInteger('amount_minor');
            $table->string('reason', 500);
            $table->timestampTz('refunded_at');
            $table->timestamps();
            $table->index(['company_id', 'branch_id', 'refunded_at']);
            $table->index(['invoice_id']);
        });

        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->constrained('users');
            $table->string('category', 32); // maintenance | fuel | insurance | tolls_parking | general
            $table->string('description', 255);
            $table->unsignedBigInteger('amount_minor');
            $table->timestampTz('incurred_at');
            $table->timestamps();
            $table->index(['company_id', 'branch_id', 'category', 'incurred_at']);
            $table->index(['company_id', 'vehicle_id']);
        });

        Schema::create('gl_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('type', 20); // asset | liability | equity | revenue | expense
            $table->boolean('is_system')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('source_type', 32); // invoice | payment | refund | expense
            $table->unsignedBigInteger('source_id');
            $table->string('reference', 120);
            $table->string('memo', 255);
            $table->timestampTz('posted_at');
            $table->timestamps();
            $table->index(['company_id', 'branch_id', 'posted_at']);
            $table->index(['company_id', 'source_type', 'source_id']);
        });

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gl_account_id')->constrained('gl_accounts');
            $table->unsignedBigInteger('debit_minor')->default(0);
            $table->unsignedBigInteger('credit_minor')->default(0);
            $table->timestamps();
            $table->index(['gl_account_id']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->unique('rental_id');
        });
        Company::query()->eachById(fn ($company) => PostJournal::seed($company->id));
    }

    public function down(): void
    {
        foreach (['journal_lines', 'journal_entries', 'gl_accounts', 'expenses', 'refunds', 'payments', 'invoice_lines', 'invoices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
