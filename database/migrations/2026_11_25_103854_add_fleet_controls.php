<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('require_inspection_photos')->default(false);
            $table->string('distance_unit', 8)->default('km'); // km | mi
            $table->string('volume_unit', 8)->default('l'); // l | gal_us
            $table->string('fuel_efficiency_unit', 16)->default('l_per_100km'); // l_per_100km | km_per_l | mpg_us | mpg_uk
            $table->string('weight_unit', 8)->default('kg'); // kg | lb
        });

        Schema::create('rate_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('vehicle_category', 64)->nullable();
            $table->unsignedBigInteger('daily_rate_minor');
            $table->unsignedBigInteger('weekly_rate_minor')->nullable();
            $table->unsignedBigInteger('monthly_rate_minor')->nullable();
            $table->unsignedBigInteger('chauffeur_daily_minor')->nullable();
            $table->unsignedInteger('daily_km_limit')->nullable();
            $table->unsignedBigInteger('excess_km_rate_minor')->default(0);
            $table->unsignedBigInteger('security_deposit_minor')->default(0);
            $table->unsignedBigInteger('late_return_hourly_minor')->default(0);
            $table->string('fuel_policy', 24)->default('full_to_full'); // full_to_full | same_to_same | prepaid
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('vehicle_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 16); // handover | return
            $table->unsignedInteger('odometer');
            $table->unsignedTinyInteger('fuel_level_pct'); // 0..100
            $table->boolean('exterior_ok')->default(true);
            $table->boolean('interior_ok')->default(true);
            $table->boolean('tyres_ok')->default(true);
            $table->boolean('spare_tyre_ok')->default(true);
            $table->boolean('tools_ok')->default(true);
            $table->boolean('accessories_ok')->default(true);
            $table->boolean('documents_ok')->default(true);
            $table->text('damage_notes')->nullable();
            $table->string('customer_acknowledged_by', 120)->nullable();
            $table->timestampTz('customer_acknowledged_at')->nullable();
            $table->timestamps();
        });

        Schema::create('maintenance_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('service_name', 120);
            $table->unsignedInteger('interval_km')->nullable();
            $table->unsignedInteger('interval_days')->nullable();
            $table->unsignedInteger('last_service_odometer')->default(0);
            $table->date('last_service_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('vehicle_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('component_type', 16); // tyre | battery
            $table->string('serial_number', 80);
            $table->string('brand_model', 120);
            $table->string('position', 16); // FL | FR | RL | RR | SPARE | BATTERY
            $table->date('installed_at');
            $table->unsignedInteger('installed_odometer')->default(0);
            $table->date('warranty_expires_at')->nullable();
            $table->string('status', 16)->default('active'); // active | replaced | scrapped
            $table->date('replaced_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('fuel_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rental_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->date('fuel_date');
            $table->string('station', 120)->nullable();
            $table->decimal('litres', 8, 2);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedInteger('odometer');
            $table->string('payment_method', 32)->default('cash');
            $table->unsignedInteger('km_since_last')->nullable();
            $table->decimal('litres_per_100km', 8, 2)->nullable();
            $table->unsignedBigInteger('cost_per_km_minor')->nullable();
            $table->boolean('is_abnormal')->default(false);
            $table->timestamps();
        });

        Schema::table('work_orders', function (Blueprint $t): void {
            $t->string('work_order_type', 32)->nullable();
            $t->text('complaint')->nullable();
            $t->text('diagnosis')->nullable();
            $t->string('technician_name', 120)->nullable();
            $t->unsignedBigInteger('estimate_minor')->nullable();
            $t->foreignId('schedule_id')->nullable()->constrained('maintenance_schedules')->nullOnDelete();
            $t->boolean('release_passed')->nullable();
            $t->text('release_notes')->nullable();
        });
        Schema::table('parts', function (Blueprint $t): void {
            $t->string('location', 120)->nullable();
        });
        Schema::table('rentals', function (Blueprint $t): void {
            $t->json('rate_card_snapshot')->nullable();
        });
        foreach (['quotations', 'rentals'] as $name) {
            Schema::table($name, function (Blueprint $t): void {
                $t->foreignId('rate_card_id')->nullable()->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $t): void {
            $t->dropColumn('rate_card_snapshot');
        });
        foreach (['rentals', 'quotations'] as $name) {
            Schema::table($name, function (Blueprint $t): void {
                $t->dropConstrainedForeignId('rate_card_id');
            });
        }
        Schema::table('work_orders', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('schedule_id');
            $t->dropColumn(['work_order_type', 'complaint', 'diagnosis', 'technician_name', 'estimate_minor', 'release_passed', 'release_notes']);
        });
        Schema::table('parts', function (Blueprint $t): void {
            $t->dropColumn('location');
        });
        foreach (['fuel_transactions', 'vehicle_components', 'maintenance_schedules', 'vehicle_inspections', 'rate_cards'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('companies', function (Blueprint $t): void {
            $t->dropColumn(['require_inspection_photos', 'distance_unit', 'volume_unit', 'fuel_efficiency_unit', 'weight_unit']);
        });
    }
};
