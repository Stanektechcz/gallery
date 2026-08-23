<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Odhadnutý den versus zapsaný den.
 *
 * Zadat první den menstruace a pak ještě čtyřikrát ťukat na další dny je práce, kterou
 * za člověka umí udělat aplikace — průměrná délka krvácení je z historie známá. Jenže
 * jakmile se předvyplněné dny přimíchají k zapsaným, začnou se průměry počítat z vlastních
 * dohadů: aplikace odhadne pět dní, z toho spočítá průměr pět, tím potvrdí sama sebe a
 * skutečnost, že tenhle cyklus trval tři dny, se nikdy nedozví.
 *
 * Proto ten příznak. Odhadnutý den se kreslí jinak, do statistik nevstupuje a v okamžiku,
 * kdy se ho člověk dotkne, přestává být odhadem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cycle_days', function (Blueprint $table) {
            $table->boolean('is_predicted')->default(false)->after('is_cycle_start');
        });
    }

    public function down(): void
    {
        Schema::table('cycle_days', function (Blueprint $table) {
            $table->dropColumn('is_predicted');
        });
    }
};
