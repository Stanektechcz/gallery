<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64)->unique();
            $table->boolean('is_enabled')->default(false);
            $table->text('encrypted_config')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_status', 24)->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('integration_settings'); }
};
