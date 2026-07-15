<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('travel_journal_recordings')) return;

        Schema::create('travel_journal_recordings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('journal_entry_id')->unique()->constrained('travel_journal_entries')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('disk', 40)->default('local');
            $table->string('path', 1024);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('sha256', 64);
            $table->timestamps();
            $table->index(['uploaded_by', 'created_at'], 'journal_recording_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_journal_recordings');
    }
};
