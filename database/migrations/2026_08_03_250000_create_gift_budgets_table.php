<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_budgets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('scope_key', 32);
            $table->unsignedSmallInteger('budget_year');
            $table->string('title', 120);
            $table->string('occasion', 80)->nullable();
            $table->decimal('planned_amount', 12, 2);
            $table->string('currency', 3)->default('CZK');
            $table->timestamps();
            $table->unique(['gallery_space_id', 'scope_key', 'budget_year', 'title'], 'gift_budgets_scope_year_title');
            $table->index(['gallery_space_id', 'budget_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_budgets');
    }
};