<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('album_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('type', 32)->default('event');
            $table->string('status', 24)->default('planned');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('timezone', 64)->default('Europe/Prague');
            $table->string('place_name', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('departure_buffer_minutes')->nullable();
            $table->json('recurrence_rule')->nullable();
            $table->string('color', 16)->nullable();
            $table->boolean('is_private')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('last_reminder_at')->nullable();
            $table->timestamps();
            $table->index(['gallery_space_id', 'starts_at']);
            $table->index(['trip_id', 'starts_at']);
        });

        Schema::create('event_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 24)->default('guest');
            $table->string('response', 24)->default('pending');
            $table->timestamps();
            $table->unique(['event_id', 'user_id']);
        });

        Schema::create('event_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 255);
            $table->text('notes')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('priority', 16)->default('normal');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['event_id', 'completed_at']);
        });

        Schema::create('event_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignId('media_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 255)->nullable();
            $table->string('external_url', 2048)->nullable();
            $table->string('reference_code', 255)->nullable();
            $table->string('kind', 32)->default('attachment');
            $table->timestamps();
        });

        Schema::create('event_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 24)->default('database');
            $table->dateTime('remind_at');
            $table->string('status', 24)->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['status', 'remind_at']);
        });

        Schema::create('travel_inbox_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('added_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('calendar_events')->nullOnDelete();
            $table->string('title', 255);
            $table->text('notes')->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->string('kind', 24)->default('idea');
            $table->string('state', 24)->default('inbox');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['gallery_space_id', 'state']);
        });

        Schema::create('trip_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('calendar_events')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title', 255);
            $table->string('category', 32)->default('other');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('CZK');
            $table->string('paid_by', 120)->nullable();
            $table->string('state', 24)->default('actual');
            $table->dateTime('occurred_at')->nullable();
            $table->json('split')->nullable();
            $table->timestamps();
            $table->index(['trip_id', 'state']);
        });

        Schema::create('trip_route_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title', 255);
            $table->string('strategy', 32)->default('custom');
            $table->json('transport_modes')->nullable();
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->decimal('estimated_cost', 12, 2)->nullable();
            $table->string('currency', 3)->default('CZK');
            $table->boolean('is_selected')->default(false);
            $table->json('data')->nullable();
            $table->timestamps();
        });

        Schema::create('time_capsules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('calendar_events')->nullOnDelete();
            $table->foreignId('media_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 255);
            $table->text('message')->nullable();
            $table->dateTime('deliver_at');
            $table->string('status', 24)->default('sealed');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'deliver_at']);
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint', 2048)->unique();
            $table->json('keys');
            $table->string('user_agent', 1024)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('event_stories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained('calendar_events')->cascadeOnDelete();
            $table->text('summary')->nullable();
            $table->json('media_ids')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_stories');
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('time_capsules');
        Schema::dropIfExists('trip_route_variants');
        Schema::dropIfExists('trip_expenses');
        Schema::dropIfExists('travel_inbox_items');
        Schema::dropIfExists('event_reminders');
        Schema::dropIfExists('event_attachments');
        Schema::dropIfExists('event_tasks');
        Schema::dropIfExists('event_participants');
        Schema::dropIfExists('calendar_events');
    }
};
