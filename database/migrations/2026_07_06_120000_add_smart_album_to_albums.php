<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            // Album type: physical (classic) | smart (auto-populated by rules)
            $table->enum('album_type', ['physical', 'smart'])
                ->default('physical')
                ->after('id');

            // Smart album rules as JSON
            $table->json('smart_rules')->nullable()->after('album_type');

            $table->index('album_type');
        });
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropIndex(['album_type']);
            $table->dropColumn(['album_type', 'smart_rules']);
        });
    }
};
