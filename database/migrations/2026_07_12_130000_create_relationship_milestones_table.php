<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('relationship_milestones')) return;
        Schema::create('relationship_milestones', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('media_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->date('occurred_on');
            $table->string('icon', 16)->default('❤️');
            $table->string('visibility', 16)->default('shared');
            $table->boolean('remind_annually')->default(true);
            $table->date('last_reminded_on')->nullable();
            $table->timestamps();
            $table->index(['gallery_space_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relationship_milestones');
    }
};
