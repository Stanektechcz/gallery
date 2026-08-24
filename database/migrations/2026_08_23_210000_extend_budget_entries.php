<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rozdělení výdaje mezi dva, účtenka u položky a spoření na cíl.
 *
 * Kdo výdaj zapsal a kdo ho zaplatil jsou dvě různé věci — dosud existoval jen `user_id`,
 * tedy ten, kdo to naťukal. Na dálku je to zrovna ten údaj, na kterém všechno stojí:
 * jeden platí nájem, druhý letenky, a bez evidence se to po půl roce nedopočítá.
 *
 * Dělení je záměrně hrubé, tři možnosti. Procenta na desetinu by u dvou lidí, kteří spolu
 * žijí, znamenala víc účetnictví než užitku; „moje / napůl / jeho" pokryje skoro všechno
 * a zbytek se zapíše jako dvě položky.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_entries', function (Blueprint $table) {
            // Kdo zaplatil. Null znamená „ten, kdo zapsal" — u starých položek nemáme jak
            // zjistit nic lepšího a tvářit se, že ano, by rozvahu rozhodilo.
            $table->unsignedBigInteger('paid_by')->nullable()->after('user_id');

            // 'none' = můj vlastní výdaj, 'equal' = napůl, 'other' = platil jsem za druhého.
            $table->string('split', 10)->default('none')->after('paid_by');

            // Účtenka. Leží v galerii jako každá jiná fotka — vlastní úložiště na účtenky
            // by znamenalo druhý systém na obrázky vedle toho, který už tu je.
            $table->unsignedBigInteger('media_item_id')->nullable()->after('note');

            $table->foreign('paid_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('media_item_id')->references('id')->on('media_items')->nullOnDelete();
        });

        Schema::table('budgets', function (Blueprint $table) {
            // Spoření na cíl. Uvnitř rozpočtu, ne vedle něj: „našetřit na letenky domů"
            // je součást téhož plánu jako nájem, ne samostatná agenda.
            $table->decimal('savings_target', 12, 2)->nullable()->after('monthly_income');
            $table->date('savings_target_on')->nullable()->after('savings_target');

            // Týdenní rozpočet pro krátké cesty. Měsíc je na čtyřdenní výlet špatná
            // jednotka — plán by se dělil číslem, které s délkou cesty nesouvisí.
            $table->string('period_unit', 10)->default('month')->after('savings_target_on');
        });
    }

    public function down(): void
    {
        Schema::table('budget_entries', function (Blueprint $table) {
            $table->dropForeign(['paid_by']);
            $table->dropForeign(['media_item_id']);
            $table->dropColumn(['paid_by', 'split', 'media_item_id']);
        });

        Schema::table('budgets', function (Blueprint $table) {
            $table->dropColumn(['savings_target', 'savings_target_on', 'period_unit']);
        });
    }
};
