<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_filters', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('module', 32);
            $t->string('name', 80);
            $t->jsonb('filters');
            $t->boolean('is_shared')->default(false);
            $t->boolean('requires_finance')->default(false);
            $t->timestamps();
            $t->index(['company_id', 'module', 'is_shared']);
            $t->index(['company_id', 'user_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_filters');
    }
};
