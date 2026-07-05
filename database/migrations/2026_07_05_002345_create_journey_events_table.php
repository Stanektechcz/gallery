<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('journey_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('story')->nullable();
            $table->date('event_date');
            $table->string('place_name', 255)->nullable();
            $table->string('emotion', 10)->nullable();
            $table->string('song_link', 512)->nullable();
            $table->json('media_uuids')->nullable();
            $table->timestamps();
            $table->index(['gallery_space_id', 'event_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journey_events');
    }
};
