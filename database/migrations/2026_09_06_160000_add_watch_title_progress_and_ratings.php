<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Co k filmům a seriálům chybělo, aby se obrazovka měla kam zapsat.
 *
 * `watch_titles` se do téhle chvíle jen četla: žebříček nad ní kreslil pásma
 * S až F, hvězdičky se daly klikat a postup v seriálu posouvat — a nic z toho
 * nepřežilo zavření záložky. Tabulka bez zápisu je horší než žádná tabulka.
 *
 * Hodnocení dostává vlastní tabulku, ne druhý sloupec: hodnotí **každý zvlášť**
 * a „7" bez toho, kdo ho dal, je ke dvojici k ničemu. Sloupec `rating` v tabulce
 * zůstává — je to společné číslo z desítky, které kreslí popisek řádku.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('watch_titles')) {
            return;
        }

        Schema::table('watch_titles', function (Blueprint $table) {
            // Pořadí v pásmu je pořadí oblíbenosti — to řekne jen člověk.
            if (! Schema::hasColumn('watch_titles', 'sort_order')) {
                $table->unsignedSmallInteger('sort_order')->default(0);
            }

            // Kolikátý díl mají za sebou a kolik jich je. Nula od „nezačali
            // jsme" pozná jen tohle: `null` znamená, že se to dílů netýká.
            if (! Schema::hasColumn('watch_titles', 'episodes_done')) {
                $table->unsignedSmallInteger('episodes_done')->nullable();
            }

            if (! Schema::hasColumn('watch_titles', 'episodes_total')) {
                $table->unsignedSmallInteger('episodes_total')->nullable();
            }
        });

        if (! Schema::hasTable('watch_title_ratings')) {
            Schema::create('watch_title_ratings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('watch_title_id')->constrained('watch_titles')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                // Hvězdičky, jedna až pět. Obrazovka jiné nenabízí.
                $table->unsignedTinyInteger('rating');
                $table->timestamps();

                $table->unique(['watch_title_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_title_ratings');

        if (! Schema::hasTable('watch_titles')) {
            return;
        }

        Schema::table('watch_titles', function (Blueprint $table) {
            foreach (['sort_order', 'episodes_done', 'episodes_total'] as $sloupec) {
                if (Schema::hasColumn('watch_titles', $sloupec)) {
                    $table->dropColumn($sloupec);
                }
            }
        });
    }
};
