<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pořadí důležitosti u vyhrazených částek.
 *
 * Dosud byla vyhrazená částka jen strop: „na potraviny nejvýš tolik". To stačí, dokud
 * peníze vycházejí. Jakmile jich je míň — nebo naopak přijde nečekaný příjem — je
 * potřeba vědět, **co se má pokrýt dřív**. Nájem před restaurací, jídlo před výlety.
 *
 * Bez pořadí by se scházející peníze musely rozdělit rovnoměrně, což je ta nejhorší
 * možnost: nezaplatí se celý nájem ani se pořádně nenajíme. S pořadím se pokrývá
 * odshora a je vidět, kde peníze došly.
 *
 * Nižší číslo je dřív. Výchozích 100 znamená „neurčeno" — dosavadní limity tím zůstanou
 * mezi sebou v pořadí podle jména a nic se nikomu nepřeskládá pod rukama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_category_limits', function (Blueprint $table) {
            $table->unsignedSmallInteger('priority')->default(100)->after('amount');
        });

        Schema::table('budgets', function (Blueprint $table) {
            // Přičítá se zapsaný příjem k rozpočtu?
            //
            // U cesty ano: jede se s pevnou sumou a každý další příjem je opravdu navíc.
            // U měsíčního rozpočtu ne — tam je výplata **sám ten rozpočet** a přičíst ji
            // by znamenalo počítat s dvojnásobkem toho, co člověk má. Tichá chyba, které
            // by si nikdo nevšiml, dokud by peníze nedošly dřív, než rozpočet slibuje.
            $table->boolean('income_adds')->default(false)->after('reserve_amount');
        });
    }

    public function down(): void
    {
        Schema::table('budget_category_limits', function (Blueprint $table) {
            $table->dropColumn('priority');
        });

        Schema::table('budgets', function (Blueprint $table) {
            $table->dropColumn('income_adds');
        });
    }
};
