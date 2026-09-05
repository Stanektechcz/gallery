<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('couple_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('couple_id')->unique();
            $table->json('data')->nullable();          // otevřený stav aplikace
            $table->text('private')->nullable();       // šifrovaně: blízkost, děti, opt-iny
            $table->unsignedInteger('rev')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('couple_states');
    }
};
