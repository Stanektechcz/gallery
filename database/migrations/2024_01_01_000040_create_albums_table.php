<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('albums', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('albums')->nullOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->unsignedSmallInteger('depth')->default(0);
            $table->string('materialized_path', 2048)->default('');
            $table->string('full_display_path', 2048)->default('');
            $table->string('drive_folder_id')->nullable();
            $table->string('drive_parent_folder_id')->nullable();
            $table->unsignedBigInteger('cover_media_id')->nullable();
            $table->text('description')->nullable();
            $table->date('event_date_start')->nullable();
            $table->date('event_date_end')->nullable();
            $table->unsignedBigInteger('default_place_id')->nullable();
            $table->string('color', 20)->nullable();
            $table->string('icon', 50)->nullable();
            $table->string('sort_mode', 30)->default('date_taken');
            $table->string('sort_direction', 4)->default('asc');
            $table->integer('manual_sort_order')->nullable();
            $table->string('visibility', 20)->default('private');
            $table->boolean('inherit_permissions')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sync_status', 30)->default('pending');
            $table->timestamp('last_drive_sync_at')->nullable();
            $table->unsignedInteger('media_count')->default(0);
            $table->unsignedInteger('descendant_count')->default(0);
            $table->bigInteger('total_size_bytes')->default(0)->unsigned();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['gallery_space_id', 'parent_id']);
            $table->index('materialized_path');
            $table->index(['gallery_space_id', 'slug']);
            $table->index('sync_status');
            $table->index('drive_folder_id');
        });

        Schema::create('album_closure', function (Blueprint $table) {
            $table->foreignId('ancestor_id')->constrained('albums')->cascadeOnDelete();
            $table->foreignId('descendant_id')->constrained('albums')->cascadeOnDelete();
            $table->unsignedSmallInteger('depth')->default(0);
            $table->primary(['ancestor_id', 'descendant_id']);
            $table->index('descendant_id');
        });

        Schema::create('album_user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['viewer', 'contributor', 'editor'])->default('viewer');
            $table->boolean('inherited')->default(false);
            $table->timestamps();
            $table->unique(['album_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('album_user_permissions');
        Schema::dropIfExists('album_closure');
        Schema::dropIfExists('albums');
    }
};
