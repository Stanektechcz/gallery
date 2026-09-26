<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Emoji reakcí se porovnávají po bajtech, ne podle jazyka.
 *
 * Provoz běží v `utf8mb4_unicode_ci` (UCA 4.0.0). Ta všem znakům mimo
 * základní rovinu — tedy skoro všem emoji — dává **stejnou** váhu, a rozdíl
 * ve variačním selektoru (❤ proti ❤️) nepočítá vůbec. Pro MySQL jsou pak 👍
 * a 😂 totéž:
 *
 *  - unikátní `chat_reactions_unique` (zpráva, člověk, emoji) odmítne druhou
 *    reakci jako duplicitu,
 *  - a přepínač v `ChatController::react()` hledá „stejnou" reakci přes
 *    `where('emoji', …)`, najde 👍 a smaže ji. Kdo po palci přidal smích,
 *    palec ztratil.
 *
 * Na SQLite (vývoj, testy) je výchozí porovnání BINARY, proto to nikdy
 * nespadlo. `utf8mb4_bin` totéž udělá i na MySQL/MariaDB. Typ, délka
 * i povinnost sloupce zůstávají, jak je založila původní migrace; unikátní
 * index zůstává taky — `MODIFY` ho jen přestaví s novým porovnáním.
 * Kolize tím může jen ubýt, nikdy přibýt, takže přestavba indexu na
 * existujících datech neselže.
 *
 * Ostatní sloupce reakcí (`media_reactions.reaction`,
 * `couple_date_idea_reactions.reaction`) nesou jen slova z pevného seznamu
 * (`love`, `maybe`, …), emoji v nich nejsou — ty se nemění.
 */
return new class extends Migration
{
    /**
     * Sloupce s emoji v unikátním indexu nebo v hledání na rovnost.
     *
     * Definice přesně podle původní migrace — `MODIFY` bez nich by sloupec
     * potichu změnil (délku, NULL). Hlídá to `EmojiReakciBinarneTest`.
     *
     * @var array<string, array<string, array{typ: string, povinny: bool}>>
     */
    public const SLOUPCE = [
        // 2026_08_09_120000_add_chat_media_and_reactions: string('emoji', 16), unikátní se zprávou a člověkem.
        'chat_reactions' => [
            'emoji' => ['typ' => 'VARCHAR(16)', 'povinny' => true],
        ],
    ];

    public const POROVNANI = 'utf8mb4_bin';

    public function up(): void
    {
        if (! $this->mysql()) {
            return;
        }

        foreach (self::SLOUPCE as $tabulka => $sloupce) {
            if (! Schema::hasTable($tabulka)) {
                continue;
            }

            foreach ($sloupce as $sloupec => $definice) {
                if (Schema::hasColumn($tabulka, $sloupec)) {
                    DB::statement(self::prikaz($tabulka, $sloupec, $definice, self::POROVNANI));
                }
            }
        }
    }

    /**
     * Zpátky se nevrací.
     *
     * Po téhle migraci smí vedle sebe ležet 👍 i 😂 od stejného člověka ke
     * stejné zprávě. Návrat k `utf8mb4_unicode_ci` by je znovu prohlásil za
     * duplicitu a přestavba unikátního indexu by spadla — nebo by se musela
     * jedna z reakcí smazat. Obojí je horší než binární porovnání navíc.
     */
    public function down(): void
    {
        //
    }

    /**
     * `ALTER TABLE … MODIFY` s danou definicí a porovnáním.
     *
     * Veřejné a bez databáze, aby šlo ověřit i tam, kde MySQL neběží.
     *
     * @param  array{typ: string, povinny: bool}  $definice
     */
    public static function prikaz(string $tabulka, string $sloupec, array $definice, string $porovnani): string
    {
        return sprintf(
            'ALTER TABLE `%s` MODIFY `%s` %s CHARACTER SET utf8mb4 COLLATE %s %s',
            $tabulka,
            $sloupec,
            $definice['typ'],
            $porovnani,
            $definice['povinny'] ? 'NOT NULL' : 'NULL',
        );
    }

    private function mysql(): bool
    {
        return in_array(DB::connection($this->getConnection())->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
