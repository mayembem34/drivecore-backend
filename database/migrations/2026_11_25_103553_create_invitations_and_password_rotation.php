<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            $t->boolean('must_change_password')->default(false);
            $t->unsignedInteger('auth_version')->default(0);
        });
        Schema::create('invitations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('email');
            $t->string('role', 64);
            $t->string('token_hash', 64)->unique();
            $t->timestampTz('expires_at');
            $t->timestampTz('accepted_at')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'email']);
            $t->index(['company_id', 'accepted_at', 'revoked_at']);
        });
        Schema::create('invitation_token_history', function (Blueprint $t): void {
            $t->id();
            $t->string('token_hash', 64)->unique();
            $t->timestampTz('invalidated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_token_history');
        Schema::dropIfExists('invitations');
        Schema::table('users', function (Blueprint $t): void {
            $t->dropColumn(['must_change_password', 'auth_version']);
        });
    }
};
