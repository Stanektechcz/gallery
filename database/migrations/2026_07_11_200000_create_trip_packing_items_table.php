<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_packing_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 255);
            $table->string('category', 32)->default('other');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->boolean('is_essential')->default(false);
            $table->boolean('is_packed')->default(false);
            $table->timestamp('packed_at')->nullable();
            $table->string('source_template', 32)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['trip_id', 'is_packed', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_packing_items');
    }
};
