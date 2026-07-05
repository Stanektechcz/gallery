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
        Schema::create('itinerary_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('country', 100)->nullable();
            $table->string('country_code', 3)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->enum('category', ['country', 'city', 'landmark', 'restaurant', 'museum', 'nature', 'other'])->default('city');
            $table->text('notes')->nullable();
            $table->enum('priority', ['dream', 'soon', 'someday'])->default('someday');
            $table->boolean('visited')->default(false);
            $table->date('visited_at')->nullable();
            $table->timestamps();
            $table->index(['gallery_space_id', 'visited']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('itinerary_places');
    }
};
