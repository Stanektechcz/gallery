<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Core trip entity
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedBigInteger('cover_media_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['gallery_space_id', 'start_date']);
        });

        // Ordered places visited during the trip
        Schema::create('trip_waypoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('place_name');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->integer('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->date('arrived_at')->nullable();
            $table->date('departed_at')->nullable();
            $table->timestamps();
            $table->index(['trip_id', 'sort_order']);
        });

        // Many-to-many: trip ↔ media_items
        Schema::create('trip_media', function (Blueprint $table) {
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('media_item_id');
            $table->timestamp('added_at')->useCurrent();
            $table->primary(['trip_id', 'media_item_id']);
            $table->foreign('media_item_id')->references('id')->on('media_items')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_media');
        Schema::dropIfExists('trip_waypoints');
        Schema::dropIfExists('trips');
    }
};
