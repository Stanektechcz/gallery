<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aby komentáře hostů nebyly tabulka, do které nikdo nepíše.
 *
 * `guest_comments` v aplikaci existuje, čte se z ní obrazovka náhledu hosta —
 * a **nikde v celé aplikaci se do ní nezapisuje**. Host, který otevře sdílený
 * odkaz, nemá jak nechat vzkaz; komentáře, které dvojice vidí, můžou vzniknout
 * jedině přímo v databázi.
 *
 * Chyběly k tomu dva sloupce:
 *
 *  - u odkazu, jestli jsou komentáře vůbec povolené. Prototyp ten přepínač má
 *    („Komentáře" u sdíleného odkazu), ale ukládal se jen do stavu v prohlížeči,
 *    takže po odhlášení platilo něco jiného, než co dvojice nastavila;
 *  - u komentáře, kde leží nahrávka. Hlasovka bez souboru je řádek, který
 *    tvrdí, že babička něco řekla, a nejde si to poslechnout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shared_links', function (Blueprint $table) {
            $table->boolean('allow_comments')->default(false)->after('allow_guest_upload');
        });

        Schema::table('guest_comments', function (Blueprint $table) {
            /*
             * Cesta na disku `public`, ne odkaz ven. Nahrávka patří dvojici
             * stejně jako fotky a nesmí viset na cizí službě, která ji jednou
             * smaže.
             */
            $table->string('audio_path')->nullable()->after('duration');
            $table->unsignedInteger('audio_bytes')->nullable()->after('audio_path');

            /*
             * Text smí chybět.
             *
             * Hlasovka nemá přepis — aplikace řeč na text nepřevádí. Prázdný
             * řetězec by znamenal „host nic neřekl", což je něco jiného než
             * „řekl to hlasem a přepis nemáme".
             */
            $table->text('body')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('guest_comments', function (Blueprint $table) {
            $table->dropColumn(['audio_path', 'audio_bytes']);
        });

        Schema::table('shared_links', function (Blueprint $table) {
            $table->dropColumn('allow_comments');
        });
    }
};
