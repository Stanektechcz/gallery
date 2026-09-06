<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datum, které nikdo nezměřil.
 *
 * Obrazovka Datování slibuje dvakrát, že přijatý odhad se zapíše „jako odhad,
 * ne jako tvrdé datum" a že „ve Zdraví dat pak není vidět jako tvrdý údaj".
 * Bez tohohle sloupce to nešlo splnit: rok odvozený ze sousedního souboru
 * vypadal v databázi stejně jako datum z EXIFu.
 *
 * Ručně zapsaný rok příznak nedostává — ten člověk ví, ne odhaduje.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->boolean('taken_at_estimated')->default(false)->after('taken_at_timezone');
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->dropColumn('taken_at_estimated');
        });
    }
};
