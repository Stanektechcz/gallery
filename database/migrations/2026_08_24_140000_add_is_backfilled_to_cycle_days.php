<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Odkud se den vzal: ze zápisu, nebo ze zpětného doplnění.
 *
 * Zpětné doplnění nabídne data odkrokovaná po typické délce cyklu. Když je člověk
 * potvrdí beze změny, vyjdou rozestupy přesně stejné — a rozbor pak hlásí „pravidelný
 * cyklus, předpovědi jsou spolehlivé". To ale není pozorování, to je aplikace, která
 * potvrzuje vlastní výpočet a tváří se, že zjistila něco o zdraví.
 *
 * Samotný záznam je platný: člověk ta data odsouhlasil a předpověď z nich má vycházet.
 * Jen se z nich nesmí odvozovat pravidelnost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cycle_days', function (Blueprint $table) {
            $table->boolean('is_backfilled')->default(false)->after('is_predicted');
        });
    }

    public function down(): void
    {
        Schema::table('cycle_days', function (Blueprint $table) {
            $table->dropColumn('is_backfilled');
        });
    }
};
