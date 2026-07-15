<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('planned_meals')) {
            Schema::create('planned_meals', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
                $table->foreignId('calendar_event_id')->nullable()->constrained('calendar_events')->cascadeOnDelete();
                $table->foreignId('trip_id')->nullable()->constrained()->cascadeOnDelete();
                $table->foreignId('trip_day_id')->nullable()->constrained('trip_days')->nullOnDelete();
                $table->foreignId('trip_activity_id')->nullable()->constrained('trip_activities')->nullOnDelete();
                $table->foreignId('cooking_session_id')->nullable()->constrained('recipe_cooking_sessions')->nullOnDelete();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->string('meal_type', 20)->default('dinner');
                $table->dateTime('planned_for');
                $table->decimal('servings', 8, 2)->default(2);
                $table->string('status', 16)->default('planned');
                $table->text('notes')->nullable();
                $table->decimal('estimated_cost', 12, 2)->nullable();
                $table->string('currency', 3)->default('CZK');
                $table->timestamps();
                $table->index(['calendar_event_id', 'planned_for'], 'planned_meals_event_date_idx');
                $table->index(['trip_id', 'planned_for'], 'planned_meals_trip_date_idx');
                $table->index(['recipe_id', 'status'], 'planned_meals_recipe_status_idx');
            });
        }

        if (! Schema::hasTable('meal_shopping_states')) {
            Schema::create('meal_shopping_states', function (Blueprint $table) {
                $table->id();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('calendar_event_id')->nullable()->constrained('calendar_events')->cascadeOnDelete();
                $table->foreignId('trip_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('item_key', 40);
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('event_task_id')->nullable()->constrained('event_tasks')->nullOnDelete();
                $table->foreignId('packing_item_id')->nullable()->constrained('trip_packing_items')->nullOnDelete();
                $table->boolean('is_checked')->default(false);
                $table->text('note')->nullable();
                $table->timestamps();
                $table->unique(['calendar_event_id', 'item_key'], 'meal_shop_event_item_uq');
                $table->unique(['trip_id', 'item_key'], 'meal_shop_trip_item_uq');
                $table->index(['gallery_space_id', 'is_checked'], 'meal_shop_space_checked_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_shopping_states');
        Schema::dropIfExists('planned_meals');
    }
};
