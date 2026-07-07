<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_searches', function (Blueprint $table) {
            $table->string('view_type', 20)->default('grid')->after('filters_json');
            $table->json('layout_config')->nullable()->after('view_type');
            $table->string('sort_by', 40)->default('taken_at')->after('layout_config');
            $table->string('sort_direction', 4)->default('desc')->after('sort_by');
            $table->string('icon', 20)->nullable()->after('sort_direction');
            $table->string('color', 20)->nullable()->after('icon');
            $table->boolean('is_pinned')->default(false)->after('is_shared');
            $table->timestamp('last_used_at')->nullable()->after('is_pinned');
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->after('description');
            $table->string('timezone', 64)->nullable()->after('end_date');
            $table->decimal('budget', 12, 2)->nullable()->after('timezone');
            $table->string('currency', 3)->default('CZK')->after('budget');
            $table->boolean('is_offline_available')->default(false)->after('currency');
        });

        Schema::create('trip_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('title')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['trip_id', 'date']);
            $table->index(['trip_id', 'sort_order']);
        });

        Schema::create('trip_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_day_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 24)->default('activity');
            $table->string('title');
            $table->text('description')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('place_name')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('status', 20)->default('planned');
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('currency', 3)->default('CZK');
            $table->json('metadata')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['trip_day_id', 'sort_order']);
        });

        Schema::create('memory_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('frequency', 20)->default('normal');
            $table->json('enabled_types')->nullable();
            $table->json('hidden_person_ids')->nullable();
            $table->json('hidden_place_ids')->nullable();
            $table->json('hidden_date_ranges')->nullable();
            $table->boolean('include_archived')->default(false);
            $table->timestamps();
        });

        Schema::create('memory_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('memory_type', 30);
            $table->string('action', 20);
            $table->timestamp('snoozed_until')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'fingerprint']);
            $table->index(['user_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_interactions');
        Schema::dropIfExists('memory_preferences');
        Schema::dropIfExists('trip_activities');
        Schema::dropIfExists('trip_days');

        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['status', 'timezone', 'budget', 'currency', 'is_offline_available']);
        });

        Schema::table('saved_searches', function (Blueprint $table) {
            $table->dropColumn(['view_type', 'layout_config', 'sort_by', 'sort_direction', 'icon', 'color', 'is_pinned', 'last_used_at']);
        });
    }
};

