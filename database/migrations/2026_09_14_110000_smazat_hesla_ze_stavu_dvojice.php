<?php

use App\Models\CoupleState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Hesla a kódy pryč ze sdíleného stavu.
 *
 * Počítačová verze posílala do stavu dvojice, co se psalo do dialogu kódu
 * zámku (heslo do galerie, starý a nový kód, obnovovací kód), kód z prvního
 * spuštění a heslo k odkazu. Stav leží v databázi otevřeně a čte ho i druhé
 * zařízení. Klient to od téhle verze neposílá a server to zahazuje; tohle
 * uklidí, co už uložené je.
 *
 * Mažou se jen klíče z `CoupleState::NEUKLADAT` — nic, co by dvojice
 * potřebovala. Revize stavu se nemění, klient si rozdíl nevšimne.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('couple_states')) {
            return;
        }

        CoupleState::query()->each(function (CoupleState $stav) {
            /*
             * A místní řádky telefonu z doby před ukládáním do databáze.
             *
             * `txExtra`, `tasksExtra`, `diary`, `secExtra` a `mShopExtra` telefon
             * u dvojice nečte ani nezapisuje — platby, úkoly, deník a nákup jsou
             * v tabulkách. Ve stavu by jen ležely a posílaly se s každým načtením.
             */
            $stav->zapomen(array_merge(CoupleState::NEUKLADAT, ['txExtra', 'tasksExtra', 'diary', 'secExtra', 'mShopExtra']));
        });
    }

    public function down(): void
    {
        // Smazaná hesla se nevracejí.
    }
};
