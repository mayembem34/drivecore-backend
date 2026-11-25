<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->string('vin', 32)->nullable();
            $table->string('category', 32)->nullable(); // sedan | suv | pickup_4x4 | van_minibus | hatchback | luxury | commercial | other
            $table->string('fuel_type', 24)->nullable(); // petrol | diesel | hybrid | electric | lpg
            $table->string('transmission', 24)->nullable(); // automatic | manual | cvt
            $table->string('current_location', 120)->nullable();
            $table->date('acquisition_date')->nullable();
            $table->unsignedBigInteger('acquisition_cost_minor')->nullable();
            $table->string('financing_type', 24)->nullable(); // cash_purchase | loan | finance_lease | operating_lease
            $table->string('financing_provider', 120)->nullable();
            $table->unsignedBigInteger('financing_monthly_minor')->nullable();
            $table->date('financing_end_date')->nullable();
            $table->unsignedBigInteger('current_book_value_minor')->nullable();
            $table->unsignedBigInteger('residual_value_minor')->nullable();
            $table->string('insurance_policy_number', 64)->nullable();
            $table->string('insurance_provider', 120)->nullable();
            $table->date('insurance_expires_at')->nullable();
            $table->date('roadworthy_expires_at')->nullable();
            $table->date('licence_expires_at')->nullable();
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->date('driver_licence_expires_at')->nullable();
            $table->string('contact_person_name', 120)->nullable();
            $table->string('contact_person_phone', 40)->nullable();
            $table->string('billing_address', 255)->nullable();
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->timestampTz('customer_accepted_at')->nullable();
            $table->string('customer_acceptance_ref', 80)->nullable();
        });

        Schema::table('rentals', function (Blueprint $table): void {
            $table->unsignedBigInteger('security_deposit_minor')->default(0);
            $table->string('deposit_status', 16)->default('none'); // none | held | refunded | retained
        });

        Schema::table('vehicle_inspections', function (Blueprint $table): void {
            $table->mediumText('signature_data_url')->nullable();
        });

        Schema::create('vehicle_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_id')->nullable()->constrained()->nullOnDelete();
            $table->string('incident_type', 24); // accident | breakdown | damage | theft_vandalism
            $table->string('severity', 16); // minor | moderate | major | total_loss
            $table->date('occurred_at');
            $table->string('location', 160)->nullable();
            $table->string('driver_name', 120)->nullable();
            $table->string('insurance_claim_number', 64)->nullable();
            $table->unsignedBigInteger('estimated_cost_minor')->nullable();
            $table->text('description');
            $table->string('status', 20)->default('reported'); // reported | assessing | repairing | closed
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_requisitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('requisition_number', 40);
            $table->string('department', 60);
            $table->date('needed_by');
            $table->text('justification');
            $table->json('items'); // [{ description, quantity, estimated_unit_minor }]
            $table->unsignedBigInteger('estimated_total_minor');
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | converted
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('approval_thresholds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32); // all 7 SRS §20 approval types
            $table->unsignedBigInteger('threshold_minor')->nullable();
            $table->decimal('threshold_pct', 5, 2)->nullable();
            $table->string('approver_role', 40); // exact SRS §3 role slug
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'type']);
        });

        Schema::create('rental_amendments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->foreignId('rental_id')->constrained()->cascadeOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('type', 24);
            $t->json('changes');
            $t->text('reason');
            $t->timestamps();
        });
        Schema::table('rentals', function (Blueprint $t): void {
            $t->unsignedBigInteger('amended_daily_rate_minor')->nullable();
            $t->text('terms')->nullable();
        });
        Schema::table('purchase_order_lines', function (Blueprint $t): void {
            $t->unsignedBigInteger('part_id')->nullable()->change();
            $t->string('description', 255)->nullable();
        });
        Schema::table('bank_transactions', function (Blueprint $t): void {
            $t->unique(['company_id', 'matched_entity_type', 'matched_entity_id'], 'bank_transaction_target_unique');
        });
        Schema::table('fixed_assets', function (Blueprint $t): void {
            $t->unique(['company_id', 'asset_code']);
            $t->unique('vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $t): void {
            $t->dropUnique(['company_id', 'asset_code']);
            $t->dropUnique(['vehicle_id']);
        });
        Schema::table('bank_transactions', function (Blueprint $t): void {
            $t->dropUnique('bank_transaction_target_unique');
        });
        Schema::table('purchase_order_lines', function (Blueprint $t): void {
            $t->dropColumn('description');
        });
        Schema::dropIfExists('rental_amendments');
        foreach (['approval_thresholds', 'purchase_requisitions', 'vehicle_incidents'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('vehicle_inspections', function (Blueprint $t): void {
            $t->dropColumn('signature_data_url');
        });
        Schema::table('rentals', function (Blueprint $t): void {
            $t->dropColumn(['security_deposit_minor', 'deposit_status', 'amended_daily_rate_minor', 'terms']);
        });
        Schema::table('quotations', function (Blueprint $t): void {
            $t->dropColumn(['customer_accepted_at', 'customer_acceptance_ref']);
        });
        Schema::table('customers', function (Blueprint $t): void {
            $t->dropColumn(['driver_licence_expires_at', 'contact_person_name', 'contact_person_phone', 'billing_address']);
        });
        Schema::table('vehicles', function (Blueprint $t): void {
            $t->dropColumn(['vin', 'category', 'fuel_type', 'transmission', 'current_location', 'acquisition_date', 'acquisition_cost_minor', 'financing_type', 'financing_provider', 'financing_monthly_minor', 'financing_end_date', 'current_book_value_minor', 'residual_value_minor', 'insurance_policy_number', 'insurance_provider', 'insurance_expires_at', 'roadworthy_expires_at', 'licence_expires_at']);
        });
    }
};
