<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Původní odhad vedle aktuálního plánu.
 *
 * Rozpočet je odhad, ne slib. Na začátku někdo řekne „na jídlo tak sedm set" a pak se
 * půl roku ukazuje, jak to bylo doopravdy. Obojí je potřeba vidět zároveň: bez původního
 * odhadu se nedá poznat, jestli se člověk spletl v plánu, nebo se změnil život.
 *
 * Dosud se při přerozdělení původní číslo přepsalo a bylo nenávratně pryč. Rozpočet pak
 * uměl říct jen „na dopravu je 650", ne „plánovali jsme 2 000 a nevyčerpáme je".
 *
 * `baseline_amount` je to, co člověk určil. `amount` je plán, se kterým se právě pracuje.
 * Vyrovnání podle skutečnosti se nikam neukládá — počítá se pokaždé znovu, takže se
 * nemůže rozejít s knihou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_category_limits', function (Blueprint $table) {
            $table->decimal('baseline_amount', 14, 2)->nullable()->after('amount');
        });

        // Dosavadní položky žádný dřívější odhad nemají; ten aktuální je pro ně zároveň
        // ten původní. Nulou nebo prázdnem by rozpočet tvrdil, že se plánovalo nic.
        DB::table('budget_category_limits')->update(['baseline_amount' => DB::raw('amount')]);

        Schema::table('budgets', function (Blueprint $table) {
            // Vyrovnávat plán podle skutečnosti sám od sebe.
            //
            // Zapnuté proto, že ruční přepočítávání nikdo nedělá a plán tím zestárne.
            // Nic se přitom neztrácí: původní odhad zůstává uložený a je pořád vidět.
            $table->boolean('auto_balance')->default(true)->after('income_adds');
        });
    }

    public function down(): void
    {
        Schema::table('budget_category_limits', function (Blueprint $table) {
            $table->dropColumn('baseline_amount');
        });

        Schema::table('budgets', function (Blueprint $table) {
            $table->dropColumn('auto_balance');
        });
    }
};
