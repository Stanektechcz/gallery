<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pojistky „přebití vlastníkem se záznamem" (rozhodnutí 27. 9. 2026).
 *
 * `users.access_revoked_at` — kdy vlastník účtu odebral přístup. Schválit
 * návrh ke smazání sám smí vlastník až po čekací lhůtě
 * (`MazaniFotek::LHUTA_BEZ_PRISTUPU_DNI`); bez data se lhůta nepočítá.
 *
 * `media_items.trash_approved_alone_at` — kdy vlastník položku schválil sám.
 * Taková položka jde trvale smazat až po celé lhůtě koše, ne dřív.
 *
 * Doplnění pro účty, kterým byl přístup odebraný už dřív: datum se nastaví
 * na **chvíli migrace**, ne na `updated_at`. `updated_at` spolehlivé není —
 * starší zásahy (migrace, hromadné `DB::table()->update`) přístup měnily bez
 * něj, takže by mohl ukázat dřívější čas, než kdy k odebrání opravdu došlo,
 * a lhůtu tím zkrátit. Čas migrace je vždycky pozdější než skutečné
 * odebrání, takže lhůta je nanejvýš delší — nikdy kratší. A vlastník kvůli
 * tomu nemusí partnerovi přístup vracet a znovu brát.
 *
 * Opakované spuštění nic nemění; na SQLite i MySQL stejně.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'access_revoked_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('access_revoked_at')->nullable();
            });

            DB::table('users')
                ->where('is_active', false)
                ->whereNull('access_revoked_at')
                ->update(['access_revoked_at' => now()]);
        }

        if (Schema::hasTable('media_items') && ! Schema::hasColumn('media_items', 'trash_approved_alone_at')) {
            Schema::table('media_items', function (Blueprint $table) {
                $table->timestamp('trash_approved_alone_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('media_items', 'trash_approved_alone_at')) {
            Schema::table('media_items', function (Blueprint $table) {
                $table->dropColumn('trash_approved_alone_at');
            });
        }

        if (Schema::hasColumn('users', 'access_revoked_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('access_revoked_at');
            });
        }
    }
};
