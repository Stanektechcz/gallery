<?php

use App\Models\FinanceCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jméno kategorie smí být v prostoru jen jednou.
 *
 * Duplicitní kategorie vznikaly souběhem: obrazovka načítá číselníky a přehled dvěma
 * paralelními požadavky a oba zakládaly výchozí sadu. Oba se zeptaly „už tu nějaká
 * je?", oba dostali „ne", a oba jich pak vyrobily dvacet.
 *
 * Časováním se to spolehlivě neopraví — kontrola a zápis nikdy nejsou jeden krok.
 * Unikát to řeší na úrovni, kde souběh neexistuje: druhý zápis prostě neprojde.
 *
 * Kdyby už duplicity v databázi byly, tahle migrace je nejdřív sloučí. Transakce se
 * přesměrují na ten záznam, který zůstane — kdyby se jen smazaly, výdaje by přišly
 * o kategorii a rozpad by se rozešel se součtem výdajů.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->duplicity() as $skupina) {
            $zustane = $skupina->first();

            foreach ($skupina->skip(1) as $navic) {
                DB::table('transactions')
                    ->where('category_id', $navic->id)
                    ->update(['category_id' => $zustane->id]);

                DB::table('budget_category_limits')
                    ->where('finance_category_id', $navic->id)
                    ->delete();

                DB::table('finance_templates')
                    ->where('finance_category_id', $navic->id)
                    ->update(['finance_category_id' => $zustane->id]);

                DB::table('finance_categories')->where('id', $navic->id)->delete();
            }
        }

        Schema::table('finance_categories', function (Blueprint $table) {
            $table->unique(['gallery_space_id', 'name', 'kind']);
        });
    }

    /** Skupiny se stejným prostorem, jménem a druhem — seřazené, aby zůstal ten první. */
    private function duplicity()
    {
        return DB::table('finance_categories')
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($k) => $k->gallery_space_id.'|'.$k->name.'|'.$k->kind)
            ->filter(fn ($s) => $s->count() > 1);
    }

    public function down(): void
    {
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->dropUnique(['gallery_space_id', 'name', 'kind']);
        });
    }
};
