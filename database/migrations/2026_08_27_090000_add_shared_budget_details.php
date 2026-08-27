<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Co potřebuje rozpočet dvou lidí, kteří nevydělávají stejně.
 *
 * Dělení mělo tři možnosti: moje, napůl, za druhého. „Napůl" je férové jen tehdy, když
 * oba vydělávají zhruba stejně — u nájmu za devět set eur to při dvojnásobném rozdílu
 * v příjmu férové není a končí to tím, že se výdaje raději nedělí vůbec a účtuje se to
 * „nějak potom".
 *
 * Poměrné dělení potřebuje vědět, kdo kolik vydělává. Rozpočet dosud znal jeden příjem
 * za celý rozpočet, což stačilo na plán, ale ne na poměr — proto samostatná tabulka
 * s příjmem po lidech. Je nepovinná: kdo ji nevyplní, dělí dál napůl a nic se nemění.
 *
 * K tomu výchozí dělení a plátce u kategorie. Nákupy jsou vždycky napůl a oblečení
 * nikdy, jenže vybírat to znovu u každé položky znamená stovky kliknutí za pololetí
 * a jednu chybu, která se najde až u vyrovnání.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Null znamená „neuvedeno". Poměrné dělení se pak nepoužije a spadne zpátky
            // na půlku — hádat příjem druhého člověka je horší než ho neznat.
            $table->decimal('monthly_income', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();

            $table->timestamps();
            $table->unique(['budget_id', 'user_id']);
        });

        Schema::table('budget_categories', function (Blueprint $table) {
            // Předvyplní se u nové položky. Null znamená „neurčeno" a formulář nabídne
            // to, co nabízel dosud — ne že by se výchozí hodnota tvářila jako volba.
            $table->string('default_split', 10)->nullable()->after('rollover');
            $table->unsignedBigInteger('default_payer')->nullable()->after('default_split');

            $table->foreign('default_payer')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('budget_categories', function (Blueprint $table) {
            $table->dropForeign(['default_payer']);
            $table->dropColumn(['default_split', 'default_payer']);
        });

        Schema::dropIfExists('budget_members');
    }
};
