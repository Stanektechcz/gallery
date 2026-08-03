<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gift_ideas', function (Blueprint $table) {
            $table->json('lifecycle')->nullable()->after('status');
            $table->string('created_from', 32)->default('manual')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('gift_ideas', function (Blueprint $table) {
            $table->dropColumn(['lifecycle', 'created_from']);
        });
    }
};