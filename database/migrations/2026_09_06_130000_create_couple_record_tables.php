<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Čtyři věci, které do aplikace patří jedině tak, že je dvojice napíše.
 *
 * Zbytek obrazovek rozhodování si vystačí s tím, co se dá spočítat — mlčky
 * platná pravidla, kdo mluví, co se rozhodlo samo. Tyhle čtyři ne. Obava
 * z rekonstrukce se nedá odvodit z transakcí a to, kdo umí přepnout bojler,
 * nestojí v žádné tabulce, dokud to někdo nenapíše.
 *
 * Proto tabulky, ne jen políčko v JSON dokumentu stavu: na všechny čtyři se
 * dá ptát napříč roky („které obavy se vyplnily", „co umí jen jeden") a to
 * je celý smysl těch obrazovek.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Kdyby jeden vypadl na týden.
         *
         * `owner_user_id` je ten, kdo tu věc umí. Prázdné znamená „oba" — a to
         * je jediný stav, který se nepočítá jako riziko. `is_documented` je,
         * jestli je to zapsané někde, kde to najde i ten druhý.
         */
        Schema::create('couple_bus_items', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind')->default('postup');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('criticality')->default(2);
            $table->boolean('is_documented')->default(false);
            $table->timestamps();

            $table->index(['gallery_space_id', 'is_documented']);
        });

        /*
         * Pre-mortem: rozhodnutí, u kterého si oba předem napíšou, co se
         * pokazí.
         *
         * `revealed_at` je okamžik odemčení. Do té chvíle nemá druhý vidět
         * nic — ne kvůli tajnostem, ale aby neopisoval strach toho prvního.
         * `outcome_note` se píše až potom, co se ukázalo, jak to dopadlo;
         * bez něj je kalibrace obav jen dojem.
         */
        Schema::create('couple_premortems', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('when_label')->nullable();
            $table->timestamp('revealed_at')->nullable();
            $table->date('closed_on')->nullable();
            $table->text('outcome_note')->nullable();
            $table->timestamps();

            $table->index(['gallery_space_id', 'closed_on']);
        });

        /*
         * Jedna obava jednoho z nich.
         *
         * `likelihood` i `severity` jsou 1–3, protože jemnější stupnice by
         * jen předstírala přesnost. Váha je jejich součin a počítá se až při
         * čtení — uložené číslo by se rozešlo se svými činiteli.
         *
         * `came_true` je nullable schválně: dokud se nezavře, není to „ne",
         * je to „zatím nevíme".
         */
        Schema::create('couple_premortem_risks', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('couple_premortem_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('risk');
            $table->unsignedTinyInteger('likelihood')->default(2);
            $table->unsignedTinyInteger('severity')->default(2);
            $table->text('mitigation')->nullable();
            $table->boolean('came_true')->nullable();
            $table->timestamps();

            $table->index(['couple_premortem_id', 'author_user_id']);
        });

        /*
         * Druhý názor od vlastní minulosti.
         *
         * Jedna tabulka pro otázku i pro případ. Rozdíl je jediný: případ má
         * `outcome` (jak jsme s tím po roce byli spokojení, 1–5), otázka ho
         * nemá, protože se teprve rozhoduje. Dvě tabulky by znamenaly
         * přepisovat řádek při zavření a ztratit, že to byla táž věc.
         */
        Schema::create('couple_past_cases', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->json('tags')->nullable();
            $table->unsignedTinyInteger('outcome')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['gallery_space_id', 'outcome']);
        });

        /*
         * Vstup, na kterém rozhodnutí stálo.
         *
         * Nájem, sazba, dojezd do práce. Revize se pak nenabízí podle data,
         * ale podle toho, že se některý z těchhle vstupů pohnul. Směr se
         * neukládá — spočítá se z obou hodnot, aby nemohl lhát.
         */
        Schema::create('couple_decision_inputs', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('couple_decision_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('value_then');
            $table->string('value_now');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('couple_decision_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('couple_decision_inputs');
        Schema::dropIfExists('couple_past_cases');
        Schema::dropIfExists('couple_premortem_risks');
        Schema::dropIfExists('couple_premortems');
        Schema::dropIfExists('couple_bus_items');
    }
};
