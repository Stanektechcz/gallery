<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * U kterého klíče se stav naposledy změnil.
 *
 * Stav páru měl jediné číslo revize pro celý dokument. Když klient stavěl na
 * starším, server vrátil 409 a **celý patch zahodil** — obrazovka se překreslila
 * podle serveru a to, co člověk mezitím napsal, zmizelo bez hlášky. Stačilo
 * k tomu mít aplikaci otevřenou na dvou zařízeních; jedno z nich pak psalo
 * do prázdna.
 *
 * S revizí u každého klíče se pozná rozdíl mezi „druhý mezitím změnil něco
 * jiného" (což není střet a zapsat se to má) a „druhý změnil právě tohle"
 * (což se musí říct).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('couple_states') || Schema::hasColumn('couple_states', 'rev_keys')) {
            return;
        }

        Schema::table('couple_states', function (Blueprint $table) {
            $table->json('rev_keys')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('couple_states') && Schema::hasColumn('couple_states', 'rev_keys')) {
            Schema::table('couple_states', function (Blueprint $table) {
                $table->dropColumn('rev_keys');
            });
        }
    }
};
