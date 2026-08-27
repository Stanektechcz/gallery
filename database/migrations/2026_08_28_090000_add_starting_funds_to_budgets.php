<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rozpočet z jedné sumy.
 *
 * Dosavadní rozpočty stojí na měsíčním příjmu: každý měsíc něco přijde a podle toho se
 * plánuje. Půlroční pobyt v cizině je jiný případ — přijede se s pevnou částkou, další
 * nepřijde a otázka nezní „kolik smím tenhle měsíc", ale „vydrží to do konce".
 *
 * Rozdíl je v tom, co je strop. U měsíčního rozpočtu je stropem plán a překročit ho
 * znamená mít mínus na papíře. U fondu je stropem hotovost a překročit ji znamená nemít
 * na nájem. Proto se ta částka ukládá zvlášť a nepřevléká se za příjem: příjem se opakuje,
 * fond je jednou a pak už jen ubývá.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            // Null znamená „tenhle rozpočet žádný fond nemá" — tedy dosavadní chování.
            // Nula je něco jiného: fond, který je prázdný. Rozlišit to jde jen null.
            $table->decimal('starting_funds', 14, 2)->nullable()->after('monthly_income');
        });
    }

    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropColumn('starting_funds');
        });
    }
};
