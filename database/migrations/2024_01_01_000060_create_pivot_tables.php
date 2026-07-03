<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Media <-> Tag pivot
        Schema::create('media_tag', function (Blueprint $table) {
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tagged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['media_item_id', 'tag_id']);
        });

        // Album <-> Tag pivot
        Schema::create('album_tag', function (Blueprint $table) {
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['album_id', 'tag_id']);
        });

        // Media <-> Person pivot
        Schema::create('media_person', function (Blueprint $table) {
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('tagged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['media_item_id', 'person_id']);
        });

        // Album <-> Person pivot
        Schema::create('album_person', function (Blueprint $table) {
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['album_id', 'person_id']);
        });

        // Media <-> Place pivot
        Schema::create('media_place', function (Blueprint $table) {
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->primary(['media_item_id', 'place_id']);
        });

        // Album <-> Place pivot
        Schema::create('album_place', function (Blueprint $table) {
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->primary(['album_id', 'place_id']);
        });

        // User favorites (per-user)
        Schema::create('user_favorites', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['user_id', 'media_item_id']);
        });

        // User ratings (per-user)
        Schema::create('user_ratings', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating')->default(0);
            $table->timestamps();
            $table->primary(['user_id', 'media_item_id']);
        });

        // Duplicate groups
        Schema::create('duplicate_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('match_type', 30)->default('exact');
            $table->string('resolution', 20)->default('unresolved');
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('duplicate_group_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('duplicate_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_kept')->default(false);
            $table->timestamps();

            $table->unique(['duplicate_group_id', 'media_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_group_items');
        Schema::dropIfExists('duplicate_groups');
        Schema::dropIfExists('user_ratings');
        Schema::dropIfExists('user_favorites');
        Schema::dropIfExists('album_place');
        Schema::dropIfExists('media_place');
        Schema::dropIfExists('album_person');
        Schema::dropIfExists('media_person');
        Schema::dropIfExists('album_tag');
        Schema::dropIfExists('media_tag');
    }
};
