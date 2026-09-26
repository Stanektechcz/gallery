<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Zaklad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;

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
        $klic = PersonalAccessToken::findToken($token);

        if ($klic === null) {
            return false;
        }

        /*
         * A že pořád platí. Přihlášení zařízení mají platnost a prošlý řádek
         * leží v tabulce ještě dva dny, než ho úklid smaže. Strážce Sanctumu se
         * nejdřív ptá sezení — prošlý token s výjimkou by tak přivezl požadavek
         * k přihlášenému sezení bez tokenu proti CSRF. Stejná pravidla jako
         * u strážce: `expires_at` a limit nečinnosti z `AppServiceProvider`.
         */
        $platny = $klic->expires_at === null || ! $klic->expires_at->isPast();

        if (is_callable(Sanctum::$accessTokenAuthenticationCallback)) {
            $platny = (bool) (Sanctum::$accessTokenAuthenticationCallback)($klic, $platny);
        }

        return $platny && $this->patriPrihlasenemu($klic);
    }

    /**
     * A že patří tomu, kdo je přihlášený v sezení — pokud tam někdo je.
     *
     * Strážce Sanctumu dá přednost sezení. Cizí platný token (třeba útočníkův
     * vlastní) přiložený k požadavku s cookie oběti by jinak výjimku dostal
     * a zápis by proběhl jako oběť, bez tokenu proti CSRF. Prototyp sezení
     * nezakládá, takže se ho to netýká; staré rozhraní posílá token proti CSRF
     * samo.
     */
    private function patriPrihlasenemu(PersonalAccessToken $klic): bool
    {
        $vSezeni = Auth::guard('web')->id();

        if ($vSezeni === null) {
            return true;
        }

        return $klic->tokenable_type === (new User)->getMorphClass()
            && (string) $klic->tokenable_id === (string) $vSezeni;
    }
}
