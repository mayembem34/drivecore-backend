<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->jsonb('metadata')->nullable();
        });
        Schema::table('rentals', function (Blueprint $table): void {
            $table->foreignId('driver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['company_id', 'driver_user_id', 'status']);
        });
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('entity_type', 32)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->index(['company_id', 'entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropColumn('metadata');
        });
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'entity_type', 'entity_id']);
            $table->dropColumn(['entity_type', 'entity_id', 'expires_at']);
        });
        Schema::table('rentals', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'driver_user_id', 'status']);
            $table->dropForeign(['driver_user_id']);
            $table->dropColumn('driver_user_id');
        });
    }
};
