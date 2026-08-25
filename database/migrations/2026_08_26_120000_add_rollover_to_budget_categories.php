<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Přenos nevyčerpaného zůstatku do dalšího měsíce.
 *
 * Měsíční plán sedí na to, co chodí každý měsíc — nájem, jídlo, doprava. Nesedí na to,
 * co přijde jednou za čas a naráz: jízdenka domů, zubař, nová bunda na zimu. U takové
 * kategorie je měsíční plán vždycky špatně. Nastavit nulu znamená, že výdaj přijde jako
 * překvapení a shodí celý měsíc. Nastavit tisíc znamená, že jedenáct měsíců z dvanácti
 * hlásí „skoro nic jsi neutratil" a nic tím neřídí.
 *
 * Kategorie s přenosem se chová jako obálka: co se v měsíci nevyčerpá, zůstává v ní na
 * příště. Sto eur měsíčně na cesty domů znamená, že po pěti měsících je na jízdenku za
 * pět set — a plán celou dobu odpovídá skutečnosti.
 *
 * Výchozí je vypnuto. Zapnutí mění význam čísla, které už člověk v přehledu vidí, a to
 * se nemá stát samo od sebe u kategorií, které vznikly dřív.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_categories', function (Blueprint $table) {
            $table->boolean('rollover')->default(false)->after('planned_monthly');
        });
    }

    public function down(): void
    {
        Schema::table('budget_categories', function (Blueprint $table) {
            $table->dropColumn('rollover');
        });
    }
};
