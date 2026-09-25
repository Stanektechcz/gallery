<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `client_id` pro přání (`gift_ideas`) a věci čekající na okno (`wellbeing_tasks`).
 *
 * Nový řádek z obrazovky nese vlastní identifikátor (`w1757…`, `k1757…`),
 * ale nikam se neukládal. Když prohlížeč poslal seznam znovu — druhá úprava
 * během rozjetého PATCH, opakování po chybě, `load()` mezi chybou a dalším
 * pokusem — server ten řádek nepoznal a založil ho podruhé. Stejný most
 * mají domácnost, sliby i rozhodnutí; unikátní klíč drží, že ani souběh
 * dvou zápisů nezaloží dvojče.
 *
 * NULL se v unikátním klíči neopakuje ani na MySQL, ani na SQLite, takže
 * dosavadní řádky bez identifikátoru klíči nevadí.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABULKY = ['gift_ideas', 'wellbeing_tasks'];

    public function up(): void
    {
        foreach (self::TABULKY as $tabulka) {
            if (! Schema::hasTable($tabulka) || Schema::hasColumn($tabulka, 'client_id')) {
                continue;
            }

            Schema::table($tabulka, function (Blueprint $table) {
                $table->string('client_id', 64)->nullable();
                $table->unique(['gallery_space_id', 'client_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABULKY as $tabulka) {
            if (! Schema::hasTable($tabulka) || ! Schema::hasColumn($tabulka, 'client_id')) {
                continue;
            }

            Schema::table($tabulka, function (Blueprint $table) {
                $table->dropUnique(['gallery_space_id', 'client_id']);
                $table->dropColumn('client_id');
            });
        }
    }
};
