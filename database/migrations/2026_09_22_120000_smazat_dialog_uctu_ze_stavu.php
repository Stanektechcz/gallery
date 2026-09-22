<?php

use App\Models\CoupleState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Dialog účtu pryč ze sdíleného stavu — pojistka.
 *
 * Rozepsaný dialog „Změnit heslo" / „Jméno a e-mail" (`acDlg`: současné
 * a nové heslo, u dvoufázového přihlášení i tajný klíč) do stavu nepatří.
 * Počítač ho vyřazuje příponou `Dlg` a telefon ho neukládá, takže tu nejspíš
 * nic není; server ho teď zahazuje i sám (CoupleState::NEUKLADAT) a tohle
 * smaže, kdyby ho sem přece jen poslal jiný klient.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('couple_states')) {
            return;
        }

        CoupleState::query()->each(fn (CoupleState $stav) => $stav->zapomen(['acDlg', 'klSrv']));
    }

    public function down(): void
    {
        // Smazaná hesla se nevracejí.
    }
};
