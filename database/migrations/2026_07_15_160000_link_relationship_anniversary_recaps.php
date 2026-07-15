<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('albums') && ! Schema::hasColumn('albums', 'anniversary_year')) {
            Schema::table('albums', function (Blueprint $table) {
                $table->unsignedSmallInteger('anniversary_year')->nullable()->after('trip_id');
                $table->unique(['gallery_space_id', 'anniversary_year'], 'albums_space_anniv_uq');
            });
        }

        if (Schema::hasTable('shared_memory_moments') && ! Schema::hasColumn('shared_memory_moments', 'album_id')) {
            Schema::table('shared_memory_moments', function (Blueprint $table) {
                $table->unsignedBigInteger('album_id')->nullable()->after('calendar_event_id');
                $table->unique('album_id', 'smm_album_uq');
                $table->foreign('album_id', 'smm_album_fk')->references('id')->on('albums')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('shared_memory_moments') && Schema::hasColumn('shared_memory_moments', 'album_id')) {
            Schema::table('shared_memory_moments', function (Blueprint $table) {
                $table->dropForeign('smm_album_fk');
                $table->dropUnique('smm_album_uq');
                $table->dropColumn('album_id');
            });
        }
        if (Schema::hasTable('albums') && Schema::hasColumn('albums', 'anniversary_year')) {
            Schema::table('albums', function (Blueprint $table) {
                $table->dropUnique('albums_space_anniv_uq');
                $table->dropColumn('anniversary_year');
            });
        }
    }
};
