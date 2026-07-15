<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recipes')) {
            Schema::create('recipes', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('cover_media_id')->nullable()->constrained('media_items')->nullOnDelete();
                $table->foreignId('album_id')->nullable()->constrained('albums')->nullOnDelete();
                $table->string('title', 180);
                $table->text('summary')->nullable();
                $table->longText('description')->nullable();
                $table->string('category', 40)->default('main_course');
                $table->string('cuisine', 80)->nullable();
                $table->string('difficulty', 16)->default('medium');
                $table->string('status', 16)->default('published');
                $table->decimal('base_servings', 8, 2)->default(2);
                $table->unsignedSmallInteger('prep_minutes')->default(0);
                $table->unsignedSmallInteger('cook_minutes')->default(0);
                $table->unsignedSmallInteger('rest_minutes')->default(0);
                $table->decimal('estimated_cost', 12, 2)->nullable();
                $table->string('currency', 3)->default('CZK');
                $table->decimal('calories_per_serving', 10, 2)->nullable();
                $table->decimal('protein_per_serving', 10, 2)->nullable();
                $table->decimal('carbs_per_serving', 10, 2)->nullable();
                $table->decimal('fat_per_serving', 10, 2)->nullable();
                $table->json('dietary_tags')->nullable();
                $table->json('occasion_tags')->nullable();
                $table->json('equipment')->nullable();
                $table->string('source_name', 180)->nullable();
                $table->string('source_url', 2048)->nullable();
                $table->text('tips')->nullable();
                $table->text('storage_notes')->nullable();
                $table->text('reheating_notes')->nullable();
                $table->boolean('is_favorite')->default(false);
                $table->timestamps();
                $table->softDeletes();
                $table->index(['gallery_space_id', 'status', 'category'], 'recipes_space_status_cat_idx');
                $table->index(['gallery_space_id', 'is_favorite'], 'recipes_space_favorite_idx');
            });
        }

        if (! Schema::hasTable('recipe_ingredients')) {
            Schema::create('recipe_ingredients', function (Blueprint $table) {
                $table->id();
                $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
                $table->string('section', 100)->nullable();
                $table->string('name', 180);
                $table->decimal('quantity', 12, 4)->nullable();
                $table->string('unit', 32)->nullable();
                $table->string('quantity_note', 120)->nullable();
                $table->boolean('is_scalable')->default(true);
                $table->boolean('is_optional')->default(false);
                $table->boolean('is_pantry')->default(false);
                $table->string('preparation', 255)->nullable();
                $table->text('substitutes')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['recipe_id', 'sort_order'], 'recipe_ing_recipe_sort_idx');
            });
        }

        if (! Schema::hasTable('recipe_steps')) {
            Schema::create('recipe_steps', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
                $table->foreignId('media_item_id')->nullable()->constrained()->nullOnDelete();
                $table->string('title', 180)->nullable();
                $table->longText('instruction');
                $table->unsignedInteger('timer_seconds')->nullable();
                $table->decimal('temperature', 6, 2)->nullable();
                $table->string('temperature_unit', 2)->default('C');
                $table->string('equipment', 255)->nullable();
                $table->text('tip')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['recipe_id', 'sort_order'], 'recipe_steps_recipe_sort_idx');
            });
        }

        if (! Schema::hasTable('recipe_cooking_sessions')) {
            Schema::create('recipe_cooking_sessions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->foreignId('calendar_event_id')->nullable()->constrained('calendar_events')->nullOnDelete();
                $table->foreignId('album_id')->nullable()->constrained('albums')->nullOnDelete();
                $table->string('status', 16)->default('planned');
                $table->dateTime('planned_for')->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('cooked_at')->nullable();
                $table->dateTime('finished_at')->nullable();
                $table->decimal('servings', 8, 2)->default(2);
                $table->unsignedSmallInteger('actual_duration_minutes')->nullable();
                foreach (['overall', 'taste', 'process', 'appearance'] as $rating) {
                    $table->decimal($rating . '_rating', 2, 1)->nullable();
                }
                $table->decimal('actual_cost', 12, 2)->nullable();
                $table->string('currency', 3)->default('CZK');
                $table->text('notes')->nullable();
                $table->text('successes')->nullable();
                $table->text('failures')->nullable();
                $table->text('improvements')->nullable();
                $table->text('changes_made')->nullable();
                $table->text('partner_feedback')->nullable();
                $table->boolean('would_cook_again')->nullable();
                $table->text('leftovers_notes')->nullable();
                $table->json('recipe_snapshot')->nullable();
                $table->timestamps();
                $table->index(['recipe_id', 'status', 'planned_for'], 'recipe_sessions_status_plan_idx');
                $table->index(['recipe_id', 'cooked_at'], 'recipe_sessions_cooked_idx');
            });
        }

        if (! Schema::hasTable('recipe_media')) {
            Schema::create('recipe_media', function (Blueprint $table) {
                $table->id();
                $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
                $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
                $table->foreignId('cooking_session_id')->nullable()->constrained('recipe_cooking_sessions')->cascadeOnDelete();
                $table->foreignId('recipe_step_id')->nullable()->constrained('recipe_steps')->nullOnDelete();
                $table->string('role', 24)->default('gallery');
                $table->string('caption', 500)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamp('created_at')->nullable();
                $table->index(['recipe_id', 'sort_order'], 'recipe_media_recipe_sort_idx');
                $table->index(['cooking_session_id', 'sort_order'], 'recipe_media_session_sort_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_media');
        Schema::dropIfExists('recipe_cooking_sessions');
        Schema::dropIfExists('recipe_steps');
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipes');
    }
};
