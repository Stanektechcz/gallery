<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Země u cesty je název, ne dvoupísmenný kód.
 *
 * Sloupec vznikl jako `string(2)`, tedy na kód typu „DE". Modul do něj ale od začátku
 * píše to, co člověk napíše do políčka „Země", a stejně to i zobrazuje — „Německo ·
 * Regensburg". Kód by musel někdo překládat na název a nikde se to neděje.
 *
 * Na MySQL to znamenalo, že **žádná cesta se zemí delší než dva znaky nešla uložit**.
 * Ve vývoji je SQLite, která délku textu nehlídá, takže se to projevilo až v produkci.
 * Sto znaků odpovídá tomu, co má `country` v ostatních tabulkách téhle databáze.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_projects', function (Blueprint $table) {
            $table->string('country', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Zpátky na dva znaky se nedá bez ztráty — delší názvy by se usekly. Sloupec
        // zůstává širší; není to nic, co by starší verze aplikace rozbilo.
    }
};
