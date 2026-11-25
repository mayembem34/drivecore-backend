<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $t): void {
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::create('messages', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $t->string('channel', 24);
            $t->text('body');
            $t->string('related_entity_type', 32)->nullable();
            $t->unsignedBigInteger('related_entity_id')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'branch_id', 'channel', 'created_at']);
        });
        Schema::create('message_reads', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('message_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestampTz('read_at');
            $t->unique(['message_id', 'user_id']);
        });
        Schema::create('user_notifications', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('required_permission', 64)->nullable();
            $t->string('severity', 16)->default('info');
            $t->string('title', 160);
            $t->string('body', 500);
            $t->string('href', 255)->nullable();
            $t->timestampTz('read_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'user_id', 'read_at']);
        });
        Schema::create('notification_reads', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_notification_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestampTz('read_at');
            $t->unique(['user_notification_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('branch_id');
        });
        foreach (['notification_reads', 'user_notifications', 'message_reads', 'messages'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
