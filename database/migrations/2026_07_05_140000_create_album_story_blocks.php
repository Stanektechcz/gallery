<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Story blocks for albums
        Schema::create('album_story_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20); // heading|text|quote|photo|video|map|divider
            $table->json('content')->nullable();   // type-specific payload
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['album_id', 'sort_order']);
        });

        // story_mode flag on albums: false = classic grid, true = story is primary view
        Schema::table('albums', function (Blueprint $table) {
            $table->boolean('story_mode')->default(false)->after('description');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('album_story_blocks');
        Schema::table('albums', function (Blueprint $table) {
            $table->dropColumn('story_mode');
        });
    }
};
