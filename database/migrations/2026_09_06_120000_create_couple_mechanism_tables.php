<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mechanismy pro dva, které si dvojice sama zapisuje.
 *
 * Sedm věcí, které se dosud ukládaly do jednoho JSON dokumentu stavu. Nebylo
 * to ztracené, ale bylo to nedosažitelné: nešlo se zeptat, kolik laskavostí
 * je nevyrovnaných, ani spojit mentální zátěž s dělbou práce, protože obojí
 * leželo v jiném světě než tabulky, se kterými zbytek aplikace pracuje.
 *
 * Co tady schválně **není**: „ticho v datech". To se nemá zapisovat — dá se
 * spočítat z toho, kdy se naposledy sáhlo do které sekce, a uložené by to
 * bylo jen druhou, zastarávající pravdou.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Účet laskavostí: co jeden vzal na sebe, aby to druhý nemusel.
         *
         * `weight` je, jak velká to byla laskavost — ne kolik stála. Peníze
         * mají vlastní tabulku a tohle s nimi nemá nic společného.
         */
        Schema::create('couple_favours', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('what');
            $table->date('happened_on');
            $table->unsignedTinyInteger('weight')->default(1);
            $table->boolean('is_settled')->default(false);
            $table->timestamps();

            $table->index(['gallery_space_id', 'is_settled']);
        });

        /*
         * Co je odpuštěné.
         *
         * `tries` je, kolikrát se to od té doby vrátilo do řeči. Odpuštěné
         * neznamená zapomenuté a tohle číslo je jediné, co o tom něco řekne.
         */
        Schema::create('couple_forgiven', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('forgiven_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('what');
            $table->date('happened_on');
            $table->unsignedSmallInteger('tries')->default(0);
            $table->timestamps();
        });

        /*
         * Anti-rozpočet: co dvojice nekoupila, zrušila nebo pustila.
         *
         * Ušetřené peníze nejsou transakce — nic se nestalo. Proto vlastní
         * tabulka a ne řádek v knize.
         */
        Schema::create('couple_anti_budget', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // `predplatne`, `vec`, `sluzba`, `jine`
            $table->string('kind', 20)->default('jine');
            $table->unsignedInteger('saved')->default(0);
            $table->string('currency', 3)->default('CZK');
            $table->date('decided_on');
            // Vrátili se k tomu a koupili to nakonec přece.
            $table->boolean('came_back')->default(false);
            $table->timestamps();
        });

        /*
         * Mentální zátěž: kdo to dělá a kdo na to musí myslet.
         *
         * V tom rozdílu je celý smysl. Dělba práce (`house_chores`) ví jen to
         * první; kdo nese hlavu, se z ní vyčíst nedá.
         */
        Schema::create('couple_mental_load', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('keeper_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('task');
            // Kolikrát za rok a kolik minut zabere samotné pamatování.
            $table->unsignedSmallInteger('times_a_year')->default(1);
            $table->unsignedSmallInteger('minutes')->default(0);
            $table->timestamps();
        });

        /*
         * Rotace kontaktu s rodinou.
         *
         * `every_days` je, jak často se ozvat; `last_contact_on` kdy naposledy
         * a kdo. Jestli je to po lhůtě, se počítá — uložený příznak by byl den
         * po termínu vedle.
         */
        Schema::create('couple_family_contacts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Čí je to strana rodiny.
            $table->foreignId('side_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('every_days')->default(7);
            $table->date('last_contact_on')->nullable();
            $table->foreignId('last_contact_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        /*
         * Dvě pravdy o jedné události.
         *
         * Ne „kdo měl pravdu" — obě verze se ukládají vedle sebe a žádná
         * nevyhrává. Sloupce jsou proto dva a stejné.
         */
        Schema::create('couple_truths', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('context')->nullable();
            $table->foreignId('first_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('first_version')->nullable();
            $table->foreignId('second_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('second_version')->nullable();
            $table->timestamps();
        });

        /*
         * Pauza: dohodnutá pravidla a to, co se po ní stalo.
         *
         * Pravidlo i záznam v jedné tabulce, rozlišené `kind` — jsou to dvě
         * poloviny jedné dohody a odděleně by se rozešly.
         */
        Schema::create('couple_pause', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            // `rule` = bod dohody, `log` = proběhlá pauza.
            $table->string('kind', 10)->default('rule');
            $table->string('text', 500);
            $table->boolean('agreed')->default(false);
            $table->foreignId('called_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('topic')->nullable();
            $table->string('outcome', 500)->nullable();
            $table->date('happened_on')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['gallery_space_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('couple_pause');
        Schema::dropIfExists('couple_truths');
        Schema::dropIfExists('couple_family_contacts');
        Schema::dropIfExists('couple_mental_load');
        Schema::dropIfExists('couple_anti_budget');
        Schema::dropIfExists('couple_forgiven');
        Schema::dropIfExists('couple_favours');
    }
};
