<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trip_reservation_imports')) return;

        Schema::create('trip_reservation_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('trip_day_id')->nullable()->constrained('trip_days')->nullOnDelete();
            $table->foreignId('trip_activity_id')->nullable()->constrained('trip_activities')->nullOnDelete();
            $table->foreignId('calendar_event_id')->nullable()->constrained('calendar_events')->nullOnDelete();
            $table->foreignId('travel_inbox_item_id')->nullable()->constrained('travel_inbox_items')->nullOnDelete();
            $table->foreignId('document_check_id')->nullable()->constrained('trip_document_checks')->nullOnDelete();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('storage_path', 1024)->nullable();
            $table->string('sha256', 64);
            $table->string('status', 24)->default('needs_review');
            $table->string('extraction_method', 32)->default('manual');
            $table->longText('source_text')->nullable();
            $table->json('extracted_data')->nullable();
            $table->json('confirmed_data')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['trip_id', 'sha256'], 'trip_reservation_import_hash_unique');
            $table->index(['trip_id', 'status'], 'trip_reservation_import_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_reservation_imports');
    }
};
