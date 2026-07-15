<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('albums') || Schema::hasColumn('albums', 'trip_id')) return;

        Schema::table('albums', function (Blueprint $table) {
            $table->foreignId('trip_id')->nullable()->after('gallery_space_id')->constrained('trips')->nullOnDelete();
            $table->unique('trip_id', 'albums_trip_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('albums') || ! Schema::hasColumn('albums', 'trip_id')) return;

        Schema::table('albums', function (Blueprint $table) {
            $table->dropUnique('albums_trip_unique');
            $table->dropForeign(['trip_id']);
            $table->dropColumn('trip_id');
        });
    }
};
