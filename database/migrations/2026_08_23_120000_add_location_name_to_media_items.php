<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Název místa u fotky, ne jen souřadnice.
 *
 * `media_items` neslo `latitude` a `longitude` a nic víc. Dvě čísla jsou dost na to
 * píchnout špendlík do mapy, ale nikoli na to, aby si člověk za pět let vzpomněl, kde
 * to bylo — a přesně kvůli tomu se poloha doplňuje ručně u snímků, kterým telefon GPS
 * nezapsal.
 *
 * Odděleně od tabulky `places`: místo, které stojí za vlastní záznam, se založí jako
 * `Place`, ale většina fotek si vystačí s tím, že u nich stojí „U babičky v Lomnici".
 * Nutit kvůli každé fotce zakládat místo by znamenalo, že poloha nedostane nikdo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->string('location_name', 255)->nullable()->after('longitude');
            $table->string('location_country', 100)->nullable()->after('location_name');

            // Odkud údaj přišel: 'exif' zapsal fotoaparát, 'manual' doplnil člověk.
            // Bez toho by hromadné přepsání polohy mohlo tiše přemazat skutečné GPS.
            $table->string('location_source', 12)->nullable()->after('location_country');
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->dropColumn(['location_name', 'location_country', 'location_source']);
        });
    }
};
