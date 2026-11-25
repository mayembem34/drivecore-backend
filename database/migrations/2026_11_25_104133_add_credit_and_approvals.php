<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('id_number', 64)->nullable();
            $table->string('driver_licence_number', 64)->nullable();
            $table->string('company_registration', 64)->nullable();
            $table->string('tin', 64)->nullable();
            $table->unsignedBigInteger('credit_limit_minor')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->boolean('is_on_credit_hold')->default(false);
            $table->string('credit_hold_reason', 255)->nullable();
        });

        Schema::create('collection_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note_type', 24); // reminder | promise_to_pay | dispute | escalation | general
            $table->unsignedBigInteger('promised_amount_minor')->nullable();
            $table->date('promised_date')->nullable();
            $table->string('status', 16)->default('open'); // open | kept | broken | resolved
            $table->text('notes');
            $table->timestamps();
        });

        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32); // discount | credit_limit | vehicle_repair | purchase | refund_writeoff | contract_amendment | vehicle_disposal
            $table->string('entity_type', 32)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('title', 160);
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('previous_value', 120)->nullable();
            $table->string('requested_value', 120);
            $table->string('approved_value', 120)->nullable();
            $table->text('reason');
            $table->text('decision_notes')->nullable();
            $table->string('status', 16)->default('pending'); // pending | approved | rejected | cancelled
            $table->timestampTz('decided_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status', 'type']);
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->string('category', 32)->nullable()->after('entity_id'); // insurance | roadworthy | vehicle_licence | driver_licence | contract | kyc | other
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $t): void {
            $t->dropColumn('category');
        });
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('collection_notes');
        Schema::table('customers', function (Blueprint $t): void {
            $t->dropColumn(['id_number', 'driver_licence_number', 'company_registration', 'tin', 'credit_limit_minor', 'payment_terms_days', 'is_on_credit_hold', 'credit_hold_reason']);
        });
    }
};
