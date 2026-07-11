<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currency_rates', function (Blueprint $table) {
            $table->id();
            $table->string('base_currency', 3);
            $table->string('quote_currency', 3);
            $table->decimal('rate', 18, 8);
            $table->date('effective_on');
            $table->string('source', 32)->default('manual');
            $table->timestamps();
            $table->unique(['base_currency', 'quote_currency', 'effective_on']);
        });

        Schema::create('trip_budget_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('category', 32);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('CZK');
            $table->unsignedTinyInteger('warn_percent')->default(80);
            $table->timestamps();
            $table->unique(['trip_id', 'category']);
        });

        Schema::create('trip_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('CZK');
            $table->string('status', 24)->default('suggested');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('trip_document_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('title', 255);
            $table->date('expires_on')->nullable();
            $table->string('status', 24)->default('required');
            $table->text('reference')->nullable();
            $table->timestamps();
            $table->index(['trip_id', 'status']);
        });

        Schema::create('saved_transport_routes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('origin', 255);
            $table->string('destination', 255);
            $table->json('preferences')->nullable();
            $table->timestamps();
        });

        Schema::create('trip_location_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            // MySQL limits identifier names to 64 characters; Laravel's generated
            // composite-index name for these columns would be 67 characters.
            $table->unique(['trip_id', 'owner_user_id', 'recipient_user_id'], 'trip_loc_share_owner_unique');
        });

        Schema::create('trip_track_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['trip_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_track_points');
        Schema::dropIfExists('trip_location_shares');
        Schema::dropIfExists('saved_transport_routes');
        Schema::dropIfExists('trip_document_checks');
        Schema::dropIfExists('trip_settlements');
        Schema::dropIfExists('trip_budget_limits');
        Schema::dropIfExists('currency_rates');
    }
};
