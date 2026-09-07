<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kód zámku aplikace patří člověku, ne souboru.
 *
 * Doteď se porovnával v prohlížeči s konstantou `LOCKPIN` z `galerie-data.js`
 * — tedy s číslem, které si server podá komukoli, kdo zná adresu skriptu.
 * Obrazovka ho navíc sama vypisovala v nápovědě nad klávesnicí.
 *
 * Kód si nastavuje každý z dvojice sám a nikdo jiný ho nevidí: ani partner,
 * ani odpověď serveru. Uloží se jako haš, stejně jako heslo — z databáze se
 * zpátky přečíst nedá, dá se jen ověřit. Vedle něj obnovovací kód, jediná
 * záloha pro chvíli, kdy si člověk kód nevzpomene.
 *
 * Sloupce jsou na `users`, ne v `user_settings`: klíč od aplikace není
 * předvolba a nemá co dělat v tabulce, jejíž obsah se posílá do obrazovky.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('app_lock_pin')->nullable()->after('two_factor_confirmed_at');
            $table->string('app_lock_recovery')->nullable()->after('app_lock_pin');
            $table->timestamp('app_lock_set_at')->nullable()->after('app_lock_recovery');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['app_lock_pin', 'app_lock_recovery', 'app_lock_set_at']);
        });
    }
};
