<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolik řádků výpisu čeká na zaúčtování (`bank_imports.rows_pending`).
 *
 * Čekající platba kartou (`PENDING`) se z výpisu neukládá — zaúčtovaná
 * přijde v dalším výpisu s jiným otiskem a obě by se sečetly. Přitom to
 * není chyba výpisu: dřív takový řádek skončil v `rows_failed` („nemá
 * platné datum") a skoro každý výpis hlásil chyby. Vlastní počet ho od
 * chyb oddělí i v historii importů.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bank_imports') || Schema::hasColumn('bank_imports', 'rows_pending')) {
            return;
        }

        Schema::table('bank_imports', function (Blueprint $table) {
            $table->unsignedInteger('rows_pending')->default(0)->after('rows_failed');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('bank_imports') && Schema::hasColumn('bank_imports', 'rows_pending')) {
            Schema::table('bank_imports', function (Blueprint $table) {
                $table->dropColumn('rows_pending');
            });
        }
    }
};
