<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('calendar_event_revisions')) return;
        Schema::create('calendar_event_revisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('calendar_event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('action', 24);
            $table->json('snapshot');
            $table->json('changed_fields')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['calendar_event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_revisions');
    }
};