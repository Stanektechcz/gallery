<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_album_id')->nullable()->constrained('albums')->nullOnDelete();
            $table->string('original_filename', 512);
            $table->string('mime_type', 100);
            $table->bigInteger('total_size')->unsigned();
            $table->unsignedInteger('total_chunks');
            $table->unsignedInteger('received_chunks')->default(0);
            $table->bigInteger('uploaded_bytes')->default(0)->unsigned();
            $table->char('sha256', 64)->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('assembled_path', 1024)->nullable();
            $table->string('drive_upload_uri', 2048)->nullable();
            $table->bigInteger('drive_uploaded_bytes')->default(0)->unsigned();
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('resulting_media_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('expires_at');
        });

        Schema::create('upload_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->string('path', 1024);
            $table->unsignedInteger('size_bytes');
            $table->char('checksum', 32)->nullable();
            $table->string('status', 20)->default('received');
            $table->timestamp('received_at');

            $table->unique(['upload_session_id', 'chunk_index']);
        });

        Schema::create('shared_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('token', 64)->unique();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('password_hash')->nullable();
            $table->boolean('allow_download')->default(true);
            $table->boolean('allow_guest_upload')->default(false);
            $table->boolean('show_metadata')->default(true);
            $table->boolean('hide_gps')->default(false);
            $table->bigInteger('upload_limit_bytes')->nullable()->unsigned();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['token', 'is_active']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('shared_link_media', function (Blueprint $table) {
            $table->foreignId('shared_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->primary(['shared_link_id', 'media_item_id']);
        });

        Schema::create('saved_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('filters_json');
            $table->boolean('is_shared')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'gallery_space_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80);
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->string('group', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('user_settings', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['user_id', 'key']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_id', 'notifiable_type', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('user_settings');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('saved_searches');
        Schema::dropIfExists('shared_link_media');
        Schema::dropIfExists('shared_links');
        Schema::dropIfExists('upload_chunks');
        Schema::dropIfExists('upload_sessions');
    }
};
