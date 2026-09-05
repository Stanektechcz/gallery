<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Zaklad;
use Illuminate\Http\Request;

/**
 * Ochrana proti cizímu odeslání formuláře — s výjimkou pro klienty, kteří se
 * hlásí tokenem.
 *
 * Bez téhle výjimky nefunguje kontrakt prototypu. Zápisy z něj (`PATCH /api/state`,
 * `POST /api/logout`) i nativní klient z README se hlásí jen hlavičkou
 * `Authorization: Bearer` — cookie nemají a token proti CSRF taky ne, takže
 * dostávali 419. V prohlížeči to bylo schované za `Sec-Fetch-Site: same-origin`,
 * které Laravel bere jako dostatečný důkaz; mimo prohlížeč se to projevilo naplno.
 *
 * Nejhůř to dopadalo u offline fronty: service worker si k zápisu uloží hlavičky
 * i s tehdejším tokenem proti CSRF a přehrává je, dokud neuspěje. Po vypršení
 * sezení už neuspěl nikdy — zápis zůstal ve frontě navždy a aplikace tvrdila, že
 * ho doručí.
 *
 * **Proč je to bezpečné.** Ochrana proti CSRF řeší jedinou věc: že prohlížeč
 * přidá k požadavku z cizí stránky cookie sám od sebe. Hlavičku `Authorization`
 * sám od sebe nepřidá nikdy a cizí stránka ji nastavit nemůže. Když tedy
 * požadavek nese **platný** token, není co zneužít. Neplatný token výjimku
 * nedostane, takže se přes „Bearer cokoliv" k sezení nikdo nepřiveze.
 */
class PreventRequestForgery extends Zaklad
{
    protected function inExceptArray($request)
    {
        return parent::inExceptArray($request) || $this->hlasiSeTokenem($request);
    }

    private function hlasiSeTokenem(Request $request): bool
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return false;
        }

        // Ověřuje se, že token existuje — ne jen že hlavička dorazila. Jinak by
        // stačilo poslat „Bearer x" a ochrana by zmizela pro každého.
        return PersonalAccessToken::findToken($token) !== null;
    }
}
