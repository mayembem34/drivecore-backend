<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('contact_name', 120);
            $table->string('company_name', 140)->nullable();
            $table->string('customer_type', 16)->default('corporate'); // individual | corporate
            $table->string('email', 255)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('source', 32)->default('corporate_outreach'); // website | referral | corporate_outreach | walk_in | phone | other
            $table->string('stage', 24)->default('new'); // new | contacted | qualified | proposal_sent | won | lost
            $table->text('vehicle_requirements')->nullable();
            $table->date('expected_start_date')->nullable();
            $table->unsignedSmallInteger('expected_days')->nullable();
            $table->unsignedBigInteger('estimated_value_minor')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('next_follow_up_at')->nullable();
            $table->string('lost_reason', 255)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'stage']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable(); // encrypted cast
            $table->text('two_factor_recovery_codes')->nullable(); // encrypted JSON of hashed single-use codes
            $table->timestampTz('two_factor_confirmed_at')->nullable();
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('require_2fa_for_privileged')->default(false);
        });

        Schema::create('two_factor_challenges', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->unsignedInteger('auth_version');
            $t->string('mode', 16);
            $t->string('device_name', 100)->nullable();
            $t->timestampTz('expires_at');
            $t->timestampTz('used_at')->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamps();
        });
        Schema::table('work_orders', function (Blueprint $t): void {
            $t->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('assigned_to_user_id');
        });
        Schema::dropIfExists('two_factor_challenges');
        Schema::dropIfExists('leads');
        Schema::table('users', function (Blueprint $t): void {
            $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
        Schema::table('companies', function (Blueprint $t): void {
            $t->dropColumn('require_2fa_for_privileged');
        });
    }
};
