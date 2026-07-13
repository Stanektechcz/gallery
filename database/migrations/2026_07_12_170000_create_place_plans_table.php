<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('place_plans')) return;
        Schema::create('place_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('calendar_event_id')->nullable()->constrained('calendar_events')->nullOnDelete();
            $table->string('state', 20)->default('planned');
            $table->date('planned_for')->nullable();
            $table->date('visited_on')->nullable();
            $table->string('reservation_reference', 255)->nullable();
            $table->string('reservation_url', 2048)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['place_id', 'planned_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_plans');
    }
};
