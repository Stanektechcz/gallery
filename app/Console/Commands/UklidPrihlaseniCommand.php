<?php

namespace App\Console\Commands;

use App\Models\PersonalAccessToken;
use App\Support\PrihlaseniZarizeni;
use Illuminate\Console\Command;

/**
 * Maže prošlá přihlášení zařízení.
 *
 * Přihlašovací tokeny mají klouzavou platnost (`PrihlaseniZarizeni`). Prošlý
 * token Sanctum nepustí, ale jeho řádek v `personal_access_tokens` zůstává —
 * bez úklidu by tabulka s každým zapomenutým telefonem jen rostla.
 *
 * Vlastní příkaz, ne `sanctum:prune-expired`: ten maže **každý** token
 * s prošlou `expires_at`, tedy i klíč k API zrušený z administrace (zrušení
 * nastaví `expires_at` na teď). Zrušený klíč se ale schválně nemaže — v seznamu
 * má zůstat jako zrušený, aby se dalo zpětně zjistit, který klíč to byl
 * a kdy přestal platit (`PersonalAccessToken::zrusen()`). Maže se proto jen
 * token se značkou přihlášení.
 *
 * Značka se hledá přes `LIKE` na uloženém JSON (`["*","prihlaseni"]`), ne přes
 * `whereJsonContains`: sloupec `abilities` je text a JSON dotaz by se na
 * SQLite (testy) a MySQL choval jinak.
 */
class UklidPrihlaseniCommand extends Command
{
    protected $signature = 'gallery:uklid-prihlaseni
        {--hodin=48 : Jak dlouho po vypršení řádek ještě nechat}';

    protected $description = 'Smaže přihlášení zařízení, která vypršela; klíče k API nechá';

    public function handle(): int
    {
        $hodin = max(0, (int) $this->option('hodin'));

        $smazano = PersonalAccessToken::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->subHours($hodin))
            ->where('abilities', 'like', '%"'.PrihlaseniZarizeni::ZNACKA.'"%')
            ->delete();

        $this->info(sprintf('Smazáno %d přihlášení zařízení prošlých před víc než %d h.', $smazano, $hodin));

        return self::SUCCESS;
    }
}
