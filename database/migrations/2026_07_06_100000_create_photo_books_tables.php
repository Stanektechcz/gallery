<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_books', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('purpose', ['photobook', 'print', 'web', 'gift', 'other'])->default('photobook');
            $table->unsignedBigInteger('cover_media_id')->nullable();
            $table->unsignedSmallInteger('item_count')->default(0);
            $table->unsignedSmallInteger('target_count')->nullable(); // e.g. 50 photos for a book
            $table->timestamps();
            $table->index(['gallery_space_id', 'created_at']);
        });

        Schema::create('photo_book_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photo_book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['photo_book_id', 'media_item_id']);
            $table->index(['photo_book_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_book_items');
        Schema::dropIfExists('photo_books');
    }
};
