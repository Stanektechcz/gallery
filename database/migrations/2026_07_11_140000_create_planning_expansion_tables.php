<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('type', 32)->default('event');
            $table->text('description')->nullable();
            $table->json('defaults')->nullable();
            $table->json('tasks')->nullable();
            $table->timestamps();
        });

        Schema::create('calendar_event_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->dateTime('occurs_at');
            $table->string('action', 16)->default('skip');
            $table->dateTime('replacement_starts_at')->nullable();
            $table->dateTime('replacement_ends_at')->nullable();
            $table->string('replacement_title', 160)->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'occurs_at']);
        });

        Schema::create('travel_wishlists', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title', 160);
            $table->boolean('is_shared')->default(true);
            $table->timestamps();
        });

        Schema::create('travel_wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wishlist_id')->constrained('travel_wishlists')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('notes')->nullable();
            $table->string('category', 32)->default('place');
            $table->string('season', 32)->nullable();
            $table->unsignedTinyInteger('priority')->default(3);
            $table->decimal('estimated_cost', 12, 2)->nullable();
            $table->string('currency', 3)->default('CZK');
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('status', 24)->default('open');
            $table->timestamps();
            $table->index(['wishlist_id', 'status', 'priority']);
        });

        Schema::create('decision_polls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('question', 255);
            $table->dateTime('closes_at')->nullable();
            $table->string('status', 24)->default('open');
            $table->timestamps();
        });

        Schema::create('decision_poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('decision_polls')->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('decision_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_option_id')->constrained('decision_poll_options')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['poll_option_id', 'user_id']);
        });

        Schema::create('travel_emergency_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('updated_by')->constrained('users')->cascadeOnDelete();
            $table->string('accommodation_name', 255)->nullable();
            $table->text('accommodation_address')->nullable();
            $table->string('accommodation_phone', 64)->nullable();
            $table->string('insurance_provider', 255)->nullable();
            $table->string('insurance_number', 255)->nullable();
            $table->json('contacts')->nullable();
            $table->json('important_numbers')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('partner_share_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 160);
            $table->boolean('is_active')->default(true);
            $table->json('filters')->nullable();
            $table->timestamp('last_previewed_at')->nullable();
            $table->timestamps();
            $table->index(['gallery_space_id', 'is_active']);
        });

        Schema::create('reminder_delivery_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_reminder_id')->constrained('event_reminders')->cascadeOnDelete();
            $table->string('channel', 24);
            $table->string('status', 24);
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['event_reminder_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_delivery_logs');
        Schema::dropIfExists('partner_share_rules');
        Schema::dropIfExists('travel_emergency_cards');
        Schema::dropIfExists('decision_poll_votes');
        Schema::dropIfExists('decision_poll_options');
        Schema::dropIfExists('decision_polls');
        Schema::dropIfExists('travel_wishlist_items');
        Schema::dropIfExists('travel_wishlists');
        Schema::dropIfExists('calendar_event_exceptions');
        Schema::dropIfExists('event_templates');
    }
};
