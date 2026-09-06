<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Klid a pohoda: co obrazovka měří a co si na ní dvojice sama nastaví.
 *
 * Čtyři věci, které se dosud kreslily z napsaných řádků a klikání do nich
 * končilo v prohlížeči: mapa energie, rozpočet pozornosti, věci čekající na
 * společné okno a otázka na dva.
 *
 * Každá z těch tabulek má svého pisatele — je jím ta obrazovka. Kapacita
 * týdne (`house_week`) ví, kdy má dvojice volno; tohle je druhá vrstva: kdy
 * má sílu. To první jde vyčíst z kalendáře, tohle ne — musí to někdo říct.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Mapa energie: sedm dní × tři části dne, tři úrovně.
         *
         * Za každého člověka zvlášť — v tom je celý smysl: hledá se okno, kdy
         * mají sílu **oba**.
         */
        Schema::create('wellbeing_energy', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // 0 = pondělí … 6 = neděle; 0 = ráno, 1 = odpoledne, 2 = večer.
            $table->unsignedTinyInteger('weekday');
            $table->unsignedTinyInteger('slot');
            $table->unsignedTinyInteger('level')->default(0);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['gallery_space_id', 'user_id', 'weekday', 'slot'], 'wellbeing_energy_unique');
        });

        /*
         * Rozpočet pozornosti: kolik chceme dát čemu.
         *
         * Ukládá se **jen přání** (`want`). Skutečnost se měří z toho, co je
         * zapsané jinde, a uložená by se s ním po prvním úklidu rozešla —
         * proto je tu `measure`, ne `real`.
         */
        Schema::create('wellbeing_attention', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('name');
            $table->unsignedSmallInteger('want')->default(0);
            // Co tuhle položku měří: `chores`, `calendar`, `dates`, `money`
            // nebo `none` — a `none` znamená, že to zatím neměří nic.
            $table->string('measure', 20)->default('none');
            $table->string('note')->nullable();
            $table->string('route', 40)->nullable();
            $table->string('tab', 40)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['gallery_space_id', 'key']);
        });

        /*
         * Co čeká na společné okno.
         *
         * `needs_people` je, kolik lidí u toho musí být — dvě, jeden, nebo
         * nikdo (dá se to dělat mimochodem). Tohle žádná jiná tabulka nenese:
         * úkol v `shared_todos` ví, kdo ho má, ne kolik jich je potřeba.
         */
        Schema::create('wellbeing_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('needs_people')->default(1);
            $table->string('route', 40)->nullable();
            $table->string('tab', 40)->nullable();
            $table->string('label')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        /*
         * Otázka na dva: jedna otázka, dvě odpovědi.
         *
         * Řádek na odpověď, ne na otázku — jinak by se sloupce jmenovaly po
         * lidech a třetí člověk by se do nich nevešel.
         */
        Schema::create('wellbeing_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('question', 500);
            $table->text('answer');
            $table->date('asked_on');
            $table->timestamps();

            $table->unique(['gallery_space_id', 'user_id', 'question', 'asked_on'], 'wellbeing_answers_unique');
            $table->index(['gallery_space_id', 'asked_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wellbeing_answers');
        Schema::dropIfExists('wellbeing_tasks');
        Schema::dropIfExists('wellbeing_attention');
        Schema::dropIfExists('wellbeing_energy');
    }
};
