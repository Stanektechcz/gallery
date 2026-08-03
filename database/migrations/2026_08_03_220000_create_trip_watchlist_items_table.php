<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_watchlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entertainment_title_id')->constrained()->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('watch_provider', 120)->nullable();
            $table->enum('offline_status', ['later', 'ready', 'unavailable'])->default('later');
            $table->text('note')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['trip_id', 'entertainment_title_id']);
            $table->index(['trip_id', 'offline_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_watchlist_items');
    }
};