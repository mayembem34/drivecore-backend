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
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('parts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 64);
            $table->string('name', 255);
            $table->unsignedBigInteger('unit_cost_minor')->default(0);
            $table->unsignedInteger('min_stock')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'branch_id', 'sku']);
        });

        Schema::create('work_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('title', 255);
            $table->string('status', 20)->default('open'); // open | in_progress | completed | cancelled
            $table->timestampTz('due_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->unsignedBigInteger('labour_cost_minor')->default(0);
            $table->unsignedBigInteger('parts_cost_minor')->default(0);
            $table->text('notes')->nullable();
            $table->text('completion_notes')->nullable();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'branch_id', 'status']);
            $table->index(['company_id', 'vehicle_id', 'status']);
        });

        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('po_number', 40);
            $table->string('status', 20)->default('draft'); // draft | submitted | approved | received | cancelled
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->text('notes')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'po_number']);
            $table->index(['company_id', 'branch_id', 'status']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_cost_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users');
            $table->integer('quantity'); // positive for receipts/positive adjustments, negative for consumption/write-offs
            $table->unsignedBigInteger('unit_cost_minor');
            $table->string('reason', 255);
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'branch_id', 'part_id']);
        });

        Company::query()->eachById(fn ($company) => PostJournal::seed($company->id));
    }

    public function down(): void
    {
        foreach (['stock_movements', 'purchase_order_lines', 'purchase_orders', 'work_orders', 'parts', 'suppliers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
