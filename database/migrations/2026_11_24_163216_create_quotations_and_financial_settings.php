<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t): void {
            $t->decimal('vat_rate_pct', 5, 2)->default(15);
            $t->decimal('discount_approval_threshold_pct', 5, 2)->default(10);
        });
        Schema::create('quotations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->unsignedInteger('rental_days');
            $t->string('status', 32)->default('approved');
            $t->decimal('discount_pct', 5, 2)->default(0);
            $t->string('discount_status', 32)->default('not_required');
            $t->foreignId('discount_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('discount_decided_at')->nullable();
            $t->string('discount_decision_notes', 500)->nullable();
            foreach (['subtotal_minor', 'discount_minor', 'vat_minor', 'total_minor'] as $column) {
                $t->unsignedBigInteger($column)->default(0);
            }
            $t->decimal('vat_rate_pct', 5, 2);
            $t->text('notes')->nullable();
            $t->timestampTz('converted_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'branch_id', 'status']);
            $t->index(['company_id', 'customer_id']);
        });
        Schema::create('quotation_vehicles', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('daily_rate_minor');
            $t->unsignedBigInteger('line_subtotal_minor');
            $t->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['quotation_id', 'vehicle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_vehicles');
        Schema::dropIfExists('quotations');
        Schema::table('companies', function (Blueprint $t): void {
            $t->dropColumn(['vat_rate_pct', 'discount_approval_threshold_pct']);
        });
    }
};
