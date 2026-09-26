<?php

namespace App\Support;

/**
 * Otisky (`'sha256-…'`) inline skriptů v hotovém dokumentu prototypu.
 *
 * Prototyp se bez inline skriptů neobejde: hlavička (`galerie.hlavicka`) nese
 * napojení na backend a telefonní dokument registraci service workera. Místo
 * `'unsafe-inline'`, které by pustilo i skript vložený chybou v escapování,
 * se do politiky vypíšou otisky přesně těchhle bloků (`PolitikaObsahu`).
 *
 * Počítá se z **odesílaného** těla, ne ze souborů: hlavička obsahuje data
 * požadavku (kdo je přihlášený, trasa), takže otisk se u každého člověka liší.
 *
 * Čtení kopíruje, jak dokument čte prohlížeč, protože otisk musí sedět na bajt:
 *
 *  - obsah skriptu končí prvním `</script` (bez ohledu na velikost písmen),
 *    uvnitř se nic nedekóduje — entity v JS zůstávají, jak jsou;
 *  - konce řádků se sjednotí na `\n`. Parser HTML to dělá s celým vstupem
 *    ještě před čtením značek — a pracovní kopie na Windows má dokumenty
 *    s CRLF (`core.autocrlf`), server s LF. Bez sjednocení by na jednom z nich
 *    otisk neseděl a hlavička by se nespustila;
 *  - skripty se `src` a datové bloky (`text/x-dc`, JSON) se přeskakují —
 *    první hlídá `'self'`, druhé prohlížeč nespouští.
 *
 * Otisk navíc (třeba skript uvnitř HTML komentáře) ničemu nevadí: povoluje jen
 * ten jediný obsah, který v dokumentu stejně je. Chybějící otisk by naopak
 * skript zablokoval, proto se typy berou široce.
 */
final class OtiskySkriptu
{
    /**
     * Typy, které prohlížeč spustí nebo podrobí `script-src`.
     *
     * JavaScriptové MIME typy podle HTML, k nim `module` a dva bloky, které
     * `script-src` hlídá taky, i když nejsou JavaScript (`importmap`,
     * `speculationrules`).
     */
    private const SPUSTITELNE = [
        'application/ecmascript', 'application/javascript', 'application/x-ecmascript',
        'application/x-javascript', 'text/ecmascript', 'text/javascript', 'text/javascript1.0',
        'text/javascript1.1', 'text/javascript1.2', 'text/javascript1.3', 'text/javascript1.4',
        'text/javascript1.5', 'text/jscript', 'text/livescript', 'text/x-ecmascript',
        'text/x-javascript', 'module', 'importmap', 'speculationrules',
    ];

    /**
     * Otevírací značka `<script …>`.
     *
     * Atributy se čtou po uvozovkách, aby `>` uvnitř hodnoty značku neukončilo
     * (`data-props` s vloženým JSON). Stejný vzor má runtime (`ATTRS`).
     */
    private const OTEVRENI = '/<script(?=[\s\/>])((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/i';

    private const ZAVRENI = '#</script[\s/>]#i';

    private const ATRIBUT = '/([^\s"\'>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/';

    /** @return list<string> */
    public static function z(string $html): array
    {
        $otisky = [];
        $pozice = 0;

        while (($shoda = self::najdi(self::OTEVRENI, $html, $pozice)) !== null) {
            $zacatek = $shoda[0][1] + strlen($shoda[0][0]);

            // Neuzavřený skript běží do konce dokumentu — stejně ho čte prohlížeč.
            $konec = self::najdi(self::ZAVRENI, $html, $zacatek)[0][1] ?? strlen($html);

            if (self::spustitelny($shoda[1][0])) {
                $otisky[] = self::otisk(substr($html, $zacatek, $konec - $zacatek));
            }

            $pozice = $konec;
        }

        return array_values(array_unique($otisky));
    }

    /**
     * Další shoda od `$od`, nebo `null`, když už žádná není.
     *
     * Chyba PCRE (limit, neplatné UTF-8) by jinak vypadala jako „žádný další
     * skript". Tiše kratší seznam by zablokoval hlavičku prototypu a aplikace
     * by se nespustila — proto výjimka a kontroler rozhodne, co dál.
     *
     * @return array<int, array{0: string, 1: int}>|null
     */
    private static function najdi(string $vzor, string $html, int $od): ?array
    {
        $vysledek = preg_match($vzor, $html, $shoda, PREG_OFFSET_CAPTURE, $od);

        if ($vysledek === false) {
            throw new \RuntimeException('Otisky skriptů nejde spočítat: '.preg_last_error_msg());
        }

        return $vysledek === 1 ? $shoda : null;
    }

    /** Otisk obsahu tak, jak ho uvidí prohlížeč. */
    public static function otisk(string $obsah): string
    {
        // Parser HTML: CRLF i samotné CR jsou `\n`, NUL ve skriptu je U+FFFD.
        $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", "\u{FFFD}"], $obsah);

        return "'sha256-".base64_encode(hash('sha256', $text, true))."'";
    }

    private static function spustitelny(string $atributy): bool
    {
        $hodnoty = [];

        preg_match_all(self::ATRIBUT, $atributy, $nalezene, PREG_SET_ORDER);

        foreach ($nalezene as $atribut) {
            $jmeno = strtolower($atribut[1]);

            // Opakovaný atribut: platí první výskyt, stejně jako v HTML.
            if (! array_key_exists($jmeno, $hodnoty)) {
                $hodnoty[$jmeno] = html_entity_decode(
                    ($atribut[2] ?? '').($atribut[3] ?? '').($atribut[4] ?? ''),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8',
                );
            }
        }

        // Vnější skript hlídá `'self'`; obsah mezi značkami prohlížeč ignoruje.
        if (array_key_exists('src', $hodnoty)) {
            return false;
        }

        if (array_key_exists('type', $hodnoty)) {
            $typ = strtolower(trim($hodnoty['type']));
        } else {
            // Bez `type` rozhoduje zastaralé `language` — `text/` + jeho hodnota.
            $jazyk = trim($hodnoty['language'] ?? '');
            $typ = $jazyk === '' ? '' : 'text/'.strtolower($jazyk);
        }

        return $typ === '' || in_array($typ, self::SPUSTITELNE, true);
    }
}
