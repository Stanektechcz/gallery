<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menstruační kalendář.
 *
 * Zdravotní údaj, ne další sdílená složka. Proto tři rozhodnutí, která tvarují všechno
 * ostatní:
 *
 * Záznam patří člověku, ne prostoru. Partner ho uvidí jedině tehdy, když si to majitelka
 * zapne, a v míře, jakou si zvolí — od „nic" přes „jen kdy čekat" až po celý deník.
 * Výchozí stav je nesdíleno; sdílení se zapíná vědomě, ne mlčky.
 *
 * Den je základní jednotka, ne cyklus. Cyklus se z dnů odvodí, protože v praxi člověk
 * zapisuje „dneska to začalo" a ne „zakládám cyklus" — a když na tři dny zapomene,
 * doplní je zpětně bez přepisování něčeho nadřazeného.
 *
 * Předpovědi se nikam neukládají. Počítají se z historie při každém dotazu, aby se po
 * doplnění zapomenutého dne rovnou opravily — uložená předpověď by po týdnu lhala a
 * nikdo by nevěděl proč.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cycle_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();

            // 'none' | 'dates' | 'full' — co partner uvidí. Nic není výchozí.
            $table->string('share_level', 10)->default('none');

            // Průměry, dokud není z čeho počítat. 28 a 5 je běžný odhad, ne diagnóza;
            // jakmile jsou v datech aspoň dva cykly, počítá se ze skutečnosti.
            $table->unsignedTinyInteger('average_cycle_days')->default(28);
            $table->unsignedTinyInteger('average_period_days')->default(5);

            $table->boolean('remind_upcoming')->default(true);
            $table->unsignedTinyInteger('remind_days_before')->default(2);
            $table->boolean('track_symptoms')->default(true);

            $table->timestamps();
            $table->unique(['user_id', 'gallery_space_id']);
        });

        Schema::create('cycle_days', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();

            $table->date('day');

            // 'none' | 'spotting' | 'light' | 'medium' | 'heavy'
            $table->string('flow', 10)->default('none');

            // Příznaky a nálada jako seznamy, ne sloupce: seznam se v čase mění a
            // přidat další znamená úpravu jednoho pole, ne migraci tabulky.
            $table->json('symptoms')->nullable();
            $table->json('moods')->nullable();

            $table->unsignedTinyInteger('pain')->nullable();
            $table->decimal('temperature', 4, 2)->nullable();
            $table->string('note', 1000)->nullable();

            // Ručně označený začátek cyklu. Většinou se pozná z toho, že po pauze zase
            // teče, ale někdy to ví jen ona — a její slovo má přednost před odhadem.
            $table->boolean('is_cycle_start')->default(false);

            $table->timestamps();

            $table->unique(['user_id', 'day']);
            $table->index(['gallery_space_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cycle_days');
        Schema::dropIfExists('cycle_settings');
    }
};
