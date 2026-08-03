<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assistant_action_receipts')) return;

        Schema::create('assistant_action_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id');
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('response');
            $table->timestamps();
            $table->unique(['request_id', 'gallery_space_id', 'user_id'], 'assistant_receipt_unique');
            $table->index(['gallery_space_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_action_receipts');
    }
};