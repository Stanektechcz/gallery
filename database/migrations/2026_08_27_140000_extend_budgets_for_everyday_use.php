<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Co rozpočtu chybí, když neslouží jen na půlroční pobyt.
 *
 * Celý systém stojí na období „od–do", protože vznikl na pobyt v cizině. Na běžnou
 * domácnost to nesedí: tam se nekončí, jen se každý měsíc začíná znovu. Klouzavý režim
 * počítá vždycky aktuální měsíc a plán se resetuje sám — bez něj by si člověk musel
 * dvanáctkrát ročně zakládat nový rozpočet.
 *
 * Cíle spoření byly dosud jedno číslo na rozpočet. „Letenky domů za čtyři sta" a „notebook
 * za dvanáct set" jsou ale dva různé cíle s různými termíny a jedno pole je nepobere.
 *
 * Rozdělení nákupu mezi kategorie řeší praktickou věc: účtenka za devadesát eur, z toho
 * šedesát jídlo a třicet drogerie. Dosud to musely být dvě položky a účtenka šla jen
 * k jedné. Řeší se to skupinou — položky zůstanou samostatné, takže součty nemají jak
 * začít lhát, jen o sobě vědí a v seznamu se ukážou pohromadě.
 *
 * Kurz u položky je poslední kousek. Systém zásadně nesčítá měny přes vymyšlený kurz,
 * ale kurz z účtenky vymyšlený není — a když ho člověk zapíše, dá se poctivě sečíst i to,
 * co se dosud sčítat nesmělo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            // 'fixed' = období od–do, 'rolling' = pořád aktuální měsíc.
            $table->string('period_mode', 10)->default('fixed')->after('period_unit');
        });

        Schema::create('budget_goals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();

            $table->string('name', 160);
            $table->decimal('target_amount', 12, 2);
            $table->string('currency', 3);

            // Kolik už je stranou. Zadává se ručně: automaticky by to znamenalo hádat,
            // které peníze na účtu patří kterému cíli.
            $table->decimal('saved_amount', 12, 2)->default(0);

            $table->date('target_on')->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['budget_id', 'sort_order']);
        });

        Schema::table('budget_entries', function (Blueprint $table) {
            // Položky z jednoho rozděleného nákupu. Null u obyčejné položky.
            $table->uuid('split_group')->nullable()->after('split');

            // Kurz k měně rozpočtu, jak ho člověk opsal z účtenky nebo výpisu. Null
            // znamená „neznáme" a taková položka se dál do součtu přes měny nepočítá.
            $table->decimal('exchange_rate', 12, 6)->nullable()->after('currency');

            $table->index('split_group');
        });
    }

    public function down(): void
    {
        Schema::table('budget_entries', function (Blueprint $table) {
            $table->dropIndex(['split_group']);
            $table->dropColumn(['split_group', 'exchange_rate']);
        });

        Schema::dropIfExists('budget_goals');

        Schema::table('budgets', function (Blueprint $table) {
            $table->dropColumn('period_mode');
        });
    }
};
