<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shared_expenses')) return;

        Schema::create('shared_expenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('calendar_event_id')->nullable()->constrained('calendar_events')->nullOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 255);
            $table->string('category', 32)->default('other');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('CZK');
            $table->dateTime('occurred_at');
            $table->string('source', 32)->default('manual');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['gallery_space_id', 'occurred_at'], 'shared_expenses_space_occurred_idx');
            $table->index(['trip_id', 'occurred_at'], 'shared_expenses_trip_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_expenses');
    }
};