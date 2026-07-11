<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_tasks', function (Blueprint $table) {
            $table->timestamp('last_escalated_at')->nullable()->after('completed_at');
        });

        Schema::create('gift_ideas', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 255);
            $table->string('occasion', 80)->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('budget', 12, 2)->nullable();
            $table->string('currency', 3)->default('CZK');
            $table->string('source_url', 2048)->nullable();
            $table->string('status', 24)->default('idea');
            $table->json('reminder_days')->nullable();
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamps();
            $table->index(['gallery_space_id', 'due_date', 'status']);
        });

        Schema::create('shared_day_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->date('note_date');
            $table->text('encrypted_content');
            $table->timestamps();
            $table->unique(['gallery_space_id', 'note_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_day_notes');
        Schema::dropIfExists('gift_ideas');
        Schema::table('event_tasks', function (Blueprint $table) { $table->dropColumn('last_escalated_at'); });
    }
};
