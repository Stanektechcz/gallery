<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sliby, žádosti mezi partnery a připomínky k nim.
 *
 * Sliby dosud žily jen ve stavu páru. Fungovalo to, ale znamenalo to, že se na
 * ně nedá zeptat odjinud — ani z týdenního přehledu, ani z připomínek — a že se
 * „dodrženo z pěti" nedá spočítat na serveru.
 *
 * Trpělivost (kolikrát se něco muselo připomínat) žádnou vlastní věc neměla:
 * je to **pohled na žádosti mezi partnery**. Proto se tu zakládají ony a jejich
 * připomínky, a trpělivost se z nich počítá. Připomínka je záznam s časem, ne
 * čítač: obrazovka mluví o posledním měsíci a z čísla se měsíc vyčíst nedá.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('couple_promises', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('client_id', 64)->nullable();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promised_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('promised_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('what', 500);
            /*
             * Termín dvakrát: slovy i datem.
             *
             * Slovy proto, že „do pátku" a „do konce příštího týdne" je přesně to,
             * co člověk vysloví; datem proto, že bez něj se nedá spočítat, kolik
             * dní je slib po termínu.
             */
            $table->string('due_label', 120)->nullable();
            $table->date('due_on')->nullable();
            /*
             * `open`, `kept`, `broken` — a `released`.
             *
             * Zrušeno po dohodě **není** nedodrženo. Ten rozdíl je celý smysl
             * téhle sekce a prototyp ho umí říct jen tím, že řádek zmizí;
             * v databázi zůstává, protože se na něj nemá zapomenout.
             */
            $table->string('state', 16)->default('open');
            // Kde to zaznělo — „v deníku 28. 8.", „nahlas u večeře".
            $table->string('said', 255)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['gallery_space_id', 'state']);
            $table->unique(['gallery_space_id', 'client_id']);
        });

        Schema::create('couple_nudges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('client_id', 64)->nullable();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asked_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('asked_of')->constrained('users')->cascadeOnDelete();
            $table->string('text', 500);
            // `cestou`, `dnes`, `tyden`, `kdyz`, `spolu`.
            $table->string('kind', 16)->default('cestou');
            // `ceka`, `prijato`, `odmitnuto`, `hotovo`.
            $table->string('state', 16)->default('ceka');
            $table->string('note', 500)->nullable();
            /*
             * Kdy to převzalo pravidlo.
             *
             * Věc, kterou obstarává automatizace, se nemá nikomu připomínat —
             * a v přehledu trpělivosti nemá co dělat.
             */
            $table->timestamp('automated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['gallery_space_id', 'state']);
            $table->unique(['gallery_space_id', 'client_id']);
        });

        Schema::create('couple_nudge_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('couple_nudge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reminded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['couple_nudge_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('couple_nudge_reminders');
        Schema::dropIfExists('couple_nudges');
        Schema::dropIfExists('couple_promises');
    }
};
