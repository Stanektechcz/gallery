<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Odemčený trezor — patří člověku, ne prohlížeči.
 *
 * Odemčení bylo jen číslo `vault_unlocked_until` v sezení. Přihlášení sezení
 * nečistí (`regenerate()` data nechává, token a otisk na sezení nesahají
 * vůbec), takže kdo se přihlásil do prohlížeče, kde si druhý před chvílí
 * trezor odemkl, měl ho odemčený taky — až patnáct minut, klidně z jiného páru.
 *
 * Vedle času se proto ukládá i kdo odemkl (`vault_unlocked_by`) a trezor je
 * otevřený jen tomu, kdo je právě přihlášený a odemykal. Samotný čas bez
 * člověka (sezení z doby před touhle změnou) neotevře nic. Tohle je jediné
 * místo, které klíče čte a zapisuje: kontroléry, výdej souborů
 * (`ProtectVaultMedia`, `MediaFileController`) i obsah obrazovek (`System`,
 * `Knihovna`) se ptají tady.
 *
 * Požadavek jen s tokenem sezení nemá, a tedy ani odemčený trezor.
 *
 * **Epocha zamčení.** Sezení samo zamčení neudrží: Laravel na konci každého
 * požadavku zapíše celé sezení, takže pomalý požadavek, který začal před
 * „Zamknout" (nahrávání, dlouhý výpis), zapsal po zamčení zpátky odemčení
 * ze svého začátku a trezor se potichu otevřel. Proto má každý účet
 * v mezipaměti serveru epochu (`trezor:epocha:{id}`), kterou výslovné
 * zamčení vymění za novou; odemčení si do sezení uloží tu, která platila,
 * a sezení s jinou epochou je zamčené — ať je v něm zapsané cokoli.
 *
 * Epocha patří účtu, ne sezení: zamčení tak zavře trezor na všech
 * zařízeních toho, kdo zamkl (je to krok pro soukromí). Přihlášení epochu
 * nemění — zapomene jen odemčení v tomhle sezení.
 *
 * Když epocha v mezipaměti chybí (smazaná mezipaměť), zamčené je každé
 * sezení, které nějakou nese — chyba vede k zamčení, ne k otevření.
 */
final class Trezor
{
    /** Do kdy odemčení platí (unixový čas). */
    public const DO = 'vault_unlocked_until';

    /** Kdo trezor odemkl (id účtu). */
    public const KDO = 'vault_unlocked_by';

    /** Epocha účtu, za které se odemykalo. */
    public const EPOCHA = 'vault_unlocked_epoch';

    /**
     * Jak dlouho epocha v mezipaměti drží. Odemčení trvá minuty; kdyby
     * epocha přece jen vypršela, trezor se zamkne a stačí odemknout znovu.
     */
    private const EPOCHA_DRZI = 60 * 60 * 24 * 365;

    /**
     * Je trezor v tomhle požadavku odemčený pro toho, kdo je přihlášený?
     *
     * `$kdo` jen tam, kde se přihlášený nepozná z výchozího strážce požadavku
     * (výdej souborů bere i token Sanctumu).
     */
    public static function odemcen(?Request $request = null, ?Authenticatable $kdo = null): bool
    {
        return self::zbyva($request, $kdo) > 0;
    }

    /** Kolik sekund odemčení ještě platí; 0 = zamčeno. */
    public static function zbyva(?Request $request = null, ?Authenticatable $kdo = null): int
    {
        $request ??= request();

        if (! $request->hasSession()) {
            return 0;
        }

        $kdo ??= $request->user();
        $sezeni = $request->session();
        $odemkl = (int) $sezeni->get(self::KDO, 0);

        if ($kdo === null || $odemkl === 0 || $odemkl !== (int) $kdo->getAuthIdentifier()) {
            return 0;
        }

        $zbyva = max(0, (int) $sezeni->get(self::DO, 0) - now()->getTimestamp());

        // Mezipaměť se ptá, jen když by sezení trezor jinak otevřelo.
        if ($zbyva === 0 || ! self::platnaEpocha($request, $odemkl, $sezeni->get(self::EPOCHA))) {
            return 0;
        }

        return $zbyva;
    }

    /** Odemkne na `$sekund` pro právě přihlášeného. */
    public static function odemkni(Request $request, int $sekund): void
    {
        $kdo = $request->user();

        if ($kdo === null || ! $request->hasSession()) {
            return;
        }

        $id = (int) $kdo->getAuthIdentifier();

        // Účet, který ještě nezamykal, dostane epochu teď. Sezení pak vždy
        // nese nějakou, a smazaná mezipaměť ho tedy zamkne. `add` je
        // atomické — dvě souběžná odemčení si epochu navzájem nepřepíšou.
        Cache::add(self::klic($id), Str::random(40), self::EPOCHA_DRZI);
        $epocha = self::epocha($request, $id, znovu: true);

        $request->session()->put([
            self::DO => now()->addSeconds($sekund)->getTimestamp(),
            self::KDO => $id,
            self::EPOCHA => $epocha,
        ]);
    }

    /**
     * Výslovné zamčení („Zamknout") — na všech zařízeních toho, kdo zamyká.
     *
     * Nová epocha zneplatní každé dosavadní odemčení účtu: v jiném
     * prohlížeči i to, které sem zpátky zapíše pomalý požadavek. Přihlášení
     * volá jen `zamkni()` — cizí zařízení zavírat nemá.
     */
    public static function zamkniVsude(Request $request): void
    {
        $kdo = $request->user();

        if ($kdo !== null) {
            $id = (int) $kdo->getAuthIdentifier();
            Cache::put(self::klic($id), Str::random(40), self::EPOCHA_DRZI);
            $request->attributes->remove(self::klicPozadavku($id));
        }

        self::zamkni($request);
    }

    /**
     * Zapomene odemčení v tomhle sezení — při každém přihlášení (heslo,
     * druhý faktor, token, otisk). Epochu nemění: přihlášení na počítači
     * nemá zavřít trezor odemčený na telefonu. Tlačítko „Zamknout" volá
     * `zamkniVsude()`.
     *
     * Kontrola `KDO` by cizí odemčení nepustila i tak; zapomenout ho při
     * přihlášení je druhá pojistka, aby v sezení nezůstalo vůbec.
     */
    public static function zamkni(?Request $request = null): void
    {
        $request ??= request();

        if ($request->hasSession()) {
            $request->session()->forget([self::DO, self::KDO, self::EPOCHA]);
        }
    }

    /**
     * Odpovídá epocha ze sezení té, která pro účet platí teď?
     *
     * Sezení bez epochy (odemčené před zavedením epoch) platí, jen dokud
     * účet žádnou nemá — první zamčení ho zavře taky.
     */
    private static function platnaEpocha(Request $request, int $id, mixed $vSezeni): bool
    {
        $aktualni = self::epocha($request, $id);

        if ($vSezeni === null || $vSezeni === '') {
            return $aktualni === null;
        }

        return $aktualni !== null && hash_equals($aktualni, (string) $vSezeni);
    }

    /**
     * Platná epocha účtu; v rámci požadavku se čte jednou.
     *
     * Obsah obrazovky se na trezor ptá u každé položky — bez zapamatování
     * by každá otázka byla dotaz do mezipaměti (v produkci do databáze).
     */
    private static function epocha(Request $request, int $id, bool $znovu = false): ?string
    {
        $klic = self::klicPozadavku($id);

        if ($znovu || ! $request->attributes->has($klic)) {
            $hodnota = Cache::get(self::klic($id));
            $request->attributes->set($klic, is_string($hodnota) && $hodnota !== '' ? $hodnota : null);
        }

        return $request->attributes->get($klic);
    }

    private static function klic(int $id): string
    {
        return 'trezor:epocha:'.$id;
    }

    private static function klicPozadavku(int $id): string
    {
        return 'trezor.epocha.'.$id;
    }
}
