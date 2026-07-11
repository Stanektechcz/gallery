<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_private_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('encrypted_content');
            $table->timestamps();
            $table->unique(['media_item_id', 'user_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('media_private_notes'); }
};
