<?php

use App\Models\CoupleState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Dialog účtu pryč ze sdíleného stavu.
 *
 * Počítač posílal rozepsaný dialog „Změnit heslo" / „Jméno a e-mail"
 * (`acDlg`: současné a nové heslo) do stavu dvojice s každým stiskem klávesy
 * a druhé zařízení ho dostalo zpátky. Klient to od téhle verze neposílá,
 * server `acDlg` zahazuje (CoupleState::NEUKLADAT); tohle uklidí, co už
 * v databázi leží.
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
