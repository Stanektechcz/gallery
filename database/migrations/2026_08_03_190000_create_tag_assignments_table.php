<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tag_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 48);
            $table->unsignedBigInteger('entity_id');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tag_id', 'entity_type', 'entity_id'], 'tag_assignment_unique');
            $table->index(['entity_type', 'entity_id'], 'tag_assignment_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_assignments');
    }
};