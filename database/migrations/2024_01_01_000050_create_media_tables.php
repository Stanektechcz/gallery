<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('primary_album_id')->nullable()->constrained('albums')->nullOnDelete();
            $table->string('drive_file_id')->nullable();
            $table->string('drive_parent_folder_id')->nullable();
            $table->string('original_filename', 512);
            $table->string('safe_filename', 512);
            $table->string('display_title', 512)->nullable();
            $table->string('extension', 20);
            $table->string('mime_type', 100);
            $table->enum('media_type', ['photo', 'video']);
            $table->bigInteger('size_bytes')->unsigned();
            $table->char('sha256', 64)->nullable();
            $table->char('md5', 32)->nullable();
            $table->string('perceptual_hash', 64)->nullable();
            $table->unsignedSmallInteger('perceptual_hash_bits')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('bitrate')->nullable();
            $table->decimal('frame_rate', 6, 3)->nullable();
            $table->string('video_codec', 30)->nullable();
            $table->string('audio_codec', 30)->nullable();
            $table->timestamp('taken_at')->nullable();
            $table->string('taken_at_timezone', 64)->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('altitude', 10, 3)->nullable();
            $table->unsignedTinyInteger('orientation')->nullable();
            $table->string('camera_make', 100)->nullable();
            $table->string('camera_model', 100)->nullable();
            $table->string('lens_model', 150)->nullable();
            $table->unsignedSmallInteger('iso')->nullable();
            $table->string('aperture', 20)->nullable();
            $table->string('shutter_speed', 20)->nullable();
            $table->string('focal_length', 20)->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('description')->nullable();
            $table->text('caption')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('processing_stage', 50)->nullable();
            $table->unsignedTinyInteger('processing_progress')->default(0);
            $table->string('storage_status', 30)->default('pending');
            $table->boolean('is_favorite')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->boolean('is_hidden')->default(false);
            $table->timestamp('trashed_at')->nullable();
            $table->timestamp('purge_after')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->text('search_text')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['gallery_space_id', 'media_type']);
            $table->index(['gallery_space_id', 'taken_at']);
            $table->index(['gallery_space_id', 'status']);
            $table->index(['gallery_space_id', 'is_favorite']);
            $table->index(['gallery_space_id', 'is_archived']);
            $table->index(['latitude', 'longitude']);
            $table->index('sha256');
            $table->index('drive_file_id');
            $table->index('trashed_at');
            $table->index('primary_album_id');
        });

        // Add FULLTEXT index only for MySQL/MariaDB
        if (\Illuminate\Support\Facades\DB::connection()->getDriverName() === 'mysql') {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE media_items ADD FULLTEXT INDEX media_items_search_text_fulltext (search_text)');
        }

        Schema::create('album_media', function (Blueprint $table) {
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_cover')->default(false);
            $table->timestamp('added_at')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->primary(['album_id', 'media_item_id']);
            $table->index(['album_id', 'sort_order']);
        });

        Schema::create('media_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('disk', 40)->default('public');
            $table->string('path', 1024);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->string('format', 20)->nullable();
            $table->string('blur_hash', 128)->nullable();
            $table->string('dominant_color', 20)->nullable();
            $table->decimal('aspect_ratio', 6, 4)->nullable();
            $table->timestamps();

            $table->unique(['media_item_id', 'type']);
            $table->index('media_item_id');
        });

        Schema::create('media_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->json('operations_json');
            $table->boolean('is_current')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['media_item_id', 'is_current']);
        });

        Schema::create('media_stacks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('cover_media_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('media_stack_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_stack_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_cover')->default(false);
            $table->timestamps();

            $table->unique(['media_stack_id', 'media_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_stack_items');
        Schema::dropIfExists('media_stacks');
        Schema::dropIfExists('media_edits');
        Schema::dropIfExists('media_variants');
        Schema::dropIfExists('album_media');
        Schema::dropIfExists('media_items');
    }
};
