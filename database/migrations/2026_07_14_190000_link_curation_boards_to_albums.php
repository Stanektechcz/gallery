<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('curation_boards')) {
            return;
        }

        Schema::table('curation_boards', function (Blueprint $table) {
            if (! Schema::hasColumn('curation_boards', 'album_id')) {
                $table->unsignedBigInteger('album_id')->nullable()->after('gallery_space_id');
                $table->foreign('album_id', 'cur_board_album_fk')->references('id')->on('albums')->cascadeOnDelete();
            }
            if (! Schema::hasColumn('curation_boards', 'purpose')) {
                $table->string('purpose', 32)->default('custom')->after('album_id');
            }
        });

        if (Schema::hasColumn('curation_boards', 'album_id') && Schema::hasColumn('curation_boards', 'purpose')) {
            Schema::table('curation_boards', function (Blueprint $table) {
                $table->index(['album_id', 'purpose'], 'cur_board_album_purpose_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('curation_boards')) {
            return;
        }

        Schema::table('curation_boards', function (Blueprint $table) {
            if (Schema::hasColumn('curation_boards', 'album_id')) {
                $table->dropForeign('cur_board_album_fk');
            }
        });
        Schema::table('curation_boards', function (Blueprint $table) {
            $columns = array_values(array_filter(['album_id', 'purpose'], fn (string $column) => Schema::hasColumn('curation_boards', $column)));
            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
