<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_spaces', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->string('drive_root_folder_id')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('gallery_space_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['owner', 'admin', 'editor', 'contributor', 'viewer'])->default('viewer');
            $table->boolean('can_delete')->default(false);
            $table->boolean('can_share')->default(true);
            $table->boolean('can_download')->default(true);
            $table->boolean('show_in_timeline')->default(true);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['gallery_space_id', 'user_id']);
        });

        Schema::create('partner_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('partner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->boolean('share_whole_library')->default(false);
            $table->boolean('show_in_timeline')->default(true);
            $table->boolean('allow_download')->default(false);
            $table->boolean('allow_reshare')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['owner_user_id', 'partner_user_id', 'gallery_space_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_shares');
        Schema::dropIfExists('gallery_space_user');
        Schema::dropIfExists('gallery_spaces');
    }
};
