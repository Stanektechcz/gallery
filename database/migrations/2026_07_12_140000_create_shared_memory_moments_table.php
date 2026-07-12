<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shared_memory_moments')) return;
        Schema::create('shared_memory_moments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('calendar_event_id')->nullable()->unique()->constrained('calendar_events')->nullOnDelete();
            $table->string('title', 160);
            $table->text('note')->nullable();
            $table->date('happened_on')->nullable();
            $table->json('media_item_ids')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->timestamps();
            $table->index(['gallery_space_id', 'happened_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_memory_moments');
    }
};
