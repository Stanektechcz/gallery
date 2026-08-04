<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entitlement layer for the SaaS offering. Billing is deliberately out of scope for now:
 * a space's plan and its add-on modules are switched on by an administrator, and the
 * price columns exist only so the pricing page has a single source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('billing_plans')) {
            Schema::create('billing_plans', function (Blueprint $table) {
                $table->id();
                $table->string('code', 40)->unique();
                $table->string('name', 120);
                $table->string('tagline', 190)->nullable();
                $table->text('description')->nullable();
                $table->unsignedInteger('price_monthly')->default(0);   // in minor units (haléře)
                $table->string('currency', 3)->default('CZK');
                $table->unsignedInteger('member_limit')->nullable();    // null = unlimited
                $table->unsignedBigInteger('storage_limit_mb')->nullable();
                $table->json('features')->nullable();                   // marketing bullets
                $table->boolean('is_public')->default(true);
                $table->boolean('is_default')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('billing_modules')) {
            Schema::create('billing_modules', function (Blueprint $table) {
                $table->id();
                $table->string('code', 40)->unique();
                $table->string('name', 120);
                $table->string('tagline', 190)->nullable();
                $table->text('description')->nullable();
                $table->unsignedInteger('price_monthly')->default(0);
                $table->string('currency', 3)->default('CZK');
                $table->string('icon', 16)->default('✨');
                $table->boolean('is_public')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        // Which plan a space is on. One row per space; history is not modelled yet.
        if (! Schema::hasTable('space_subscriptions')) {
            Schema::create('space_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('billing_plan_id')->constrained()->cascadeOnDelete();
                $table->string('status', 16)->default('active');   // active | trialing | paused
                $table->timestamp('started_at')->nullable();
                $table->timestamp('ends_at')->nullable();          // null = open ended
                $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('note', 190)->nullable();
                $table->timestamps();
                $table->unique('gallery_space_id', 'space_subscription_unique');
            });
        }

        // Add-on modules switched on for a space, independently of its plan.
        if (! Schema::hasTable('space_modules')) {
            Schema::create('space_modules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('billing_module_id')->constrained()->cascadeOnDelete();
                $table->string('status', 16)->default('active');   // active | trialing | paused
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['gallery_space_id', 'billing_module_id'], 'space_module_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('space_modules');
        Schema::dropIfExists('space_subscriptions');
        Schema::dropIfExists('billing_modules');
        Schema::dropIfExists('billing_plans');
    }
};
