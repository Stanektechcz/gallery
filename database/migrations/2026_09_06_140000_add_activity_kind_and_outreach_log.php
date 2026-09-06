<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dva chybějící sloupce pro poslední čtyři obrazovky.
 *
 * „Účet radosti", „neviditelná práce" a „kdo mluví za koho" se dosud kreslily
 * z ukázkových dat a napojit je nešlo — ne proto, že by chyběl nápad, jak to
 * spočítat, ale proto, že v databázi nebyl **jeden konkrétní údaj**:
 *
 *  - u události v kalendáři chybělo, co to za společnou věc vlastně bylo.
 *    Typ (`event`, `birthday`) říká, jak se to chová v kalendáři, ne jestli
 *    to byla snídaně mimo domov nebo návštěva u rodiny;
 *  - nikde nebylo zapsané, **kdo to vyřídil**. Tabulka kontaktů zná jen
 *    „naposledy" a „jak často", takže se z ní nedalo spočítat, kolik hodin
 *    to komu sebralo ani kdo za koho mluví s úřady.
 *
 * Čtvrtá obrazovka — mlčky platná pravidla — nový sloupec nepotřebuje: hledá
 * se v tom, co už se zapisuje (dělba práce, kalendář, transakce).
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Co to bylo za společnou věc.
         *
         * Nepovinné a mimo `type`: ten popisuje chování události v kalendáři
         * (narozeniny se opakují každý rok, běžná událost ne). Tohle je druh
         * společně stráveného času a jeden bez druhého dávají smysl.
         */
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->string('activity_kind')->nullable()->after('type');
            $table->index(['gallery_space_id', 'activity_kind']);
        });

        /*
         * Kdo to vyřídil.
         *
         * Jeden protokol pro dvě obrazovky. „Neviditelná práce" je jeho část
         * navázaná na kontakt s rodinou (`couple_family_contact_id`), „kdo
         * mluví za koho" je týž protokol seskupený po oblastech.
         *
         * `asked_partner` je jediná věc, kterou nejde odvodit: jestli se ten,
         * kdo to vyřizoval, předem zeptal druhého. Bez toho se nedá říct, kde
         * se rozhoduje za oba, aniž by o tom druhý věděl — a to je celý smysl
         * té obrazovky.
         *
         * `minutes` je nullable schválně. Nula by znamenala „nezabralo to
         * čas", což je něco jiného než „nikdo to neměřil".
         */
        Schema::create('couple_outreach_log', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('couple_family_contact_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('area');
            $table->foreignId('by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('happened_on');
            $table->unsignedSmallInteger('minutes')->nullable();
            $table->boolean('asked_partner')->default(false);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['gallery_space_id', 'happened_on']);
            $table->index(['gallery_space_id', 'area']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('couple_outreach_log');

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropIndex(['gallery_space_id', 'activity_kind']);
            $table->dropColumn('activity_kind');
        });
    }
};
