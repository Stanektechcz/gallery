<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('album_suggestion_decisions')) return;

        Schema::create('album_suggestion_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('fingerprint', 64);
            $table->string('action', 16);
            $table->foreignId('album_id')->nullable()->constrained()->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Explicit short names also work on MySQL's 64-character identifier limit.
            $table->unique(['gallery_space_id', 'fingerprint'], 'album_sugg_space_fp_uq');
            $table->index(['gallery_space_id', 'action'], 'album_sugg_space_action_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('album_suggestion_decisions');
    }
};
