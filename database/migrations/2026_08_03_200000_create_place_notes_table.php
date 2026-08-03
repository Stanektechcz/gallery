<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('visibility', ['shared', 'personal'])->default('personal');
            $table->string('scope_key', 48); // shared or personal:{userId}; makes each scope unique
            $table->text('content');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['place_id', 'scope_key']);
            $table->index(['place_id', 'visibility']);
            $table->index(['place_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_notes');
    }
};