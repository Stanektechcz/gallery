<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curation_boards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('visibility', 20)->default('shared');
            $table->timestamps();
            $table->index(['gallery_space_id', 'updated_at']);
        });

        Schema::create('curation_board_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curation_board_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->text('note')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['curation_board_id', 'media_item_id']);
            $table->index(['curation_board_id', 'status', 'sort_order']);
        });

        Schema::create('curation_board_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curation_board_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_selected');
            $table->timestamps();
            $table->unique(['curation_board_item_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curation_board_votes');
        Schema::dropIfExists('curation_board_items');
        Schema::dropIfExists('curation_boards');
    }
};
