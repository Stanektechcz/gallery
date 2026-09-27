<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Text z vyhledávacího pole jako bezpečný dotaz pro MySQL FULLTEXT v režimu BOOLEAN.
 *
 * Dřív šel do `whereFullText(…, ['mode' => 'boolean'])` holý text. Jenže
 * v booleovském režimu jsou `+ - < > ( ) ~ * " @` operátory: e-mail
 * (`adri@seznam.cz`) nebo neuzavřená uvozovka skončí v InnoDB chybou syntaxe
 * a hledání spadne na 500. A slova kratší než `innodb_ft_min_token_size`
 * (výchozí 3) v indexu vůbec nejsou — dotaz „ok" nenašel nic, ani to, co by
 * našel `LIKE`. Na SQLite (testy) se FULLTEXT nepoužívá, proto to nikdy
 * nespadlo.
 *
 * Tady se text rozdělí na slova (písmena, číslice, podtržítko — stejně jako
 * je dělí InnoDB), krátká a stopslova se zahodí a každé zbylé je povinné
 * a hledané od začátku (`+slovo*`). Povinné proto, že `LIKE` na SQLite
 * a v ostatních sloupcích hledá celý text: „moře Chorvatsko" má najít moře
 * v Chorvatsku, ne každé moře. Od začátku proto, že čeština skloňuje —
 * „Prah" má najít „Praha" i „Prahy", jako to najde `LIKE`.
 *
 * Když nezbude nic, vrací `null` a volající hledá přes `LIKE`.
 */
final class FulltextDotaz
{
    /** `innodb_ft_min_token_size` ve výchozím nastavení MySQL 8. */
    public const MIN_DELKA_SLOVA = 3;

    /**
     * Výchozí stopslova InnoDB (`INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD`),
     * jen ta od tří znaků — kratší zahodí už délka.
     *
     * V indexu nejsou, takže jako povinné slovo by dotaz nenašel nic: „mail.com"
     * by se rozpadlo na `+mail* +com*` a `com` v indexu chybí.
     */
    private const STOPSLOVA = [
        'about', 'are', 'com', 'for', 'from', 'how', 'that', 'the', 'this',
        'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www',
    ];

    /**
     * Koncovky, které lehký kmen utrhne — nejdelší napřed.
     *
     * Čeština skloňuje, takže „výletech" v textu fotky nenajde „výlet".
     * Plný slovník tvarů tu není; stačí utrhnout nejčastější pádové
     * a přídavné koncovky a hledat od začátku slova (`kmen*`, `LIKE %kmen%`).
     * Každá koncovka je tu i bez diakritiky — kdo píše bez háčků, píše
     * „vyletach", ne „výletách".
     */
    private const KONCOVKY = [
        'ech', 'ách', 'ach', 'ích', 'ich', 'ých', 'ych', 'ami', 'ové', 'ove',
        'ou', 'em', 'ům', 'um', 'u', 'y', 'a', 'e', 'i', 'é', 'á', 'ý', 'í',
    ];

    /** Kolik znaků musí po utržení koncovky zbýt — kratší kmen by chytal cokoli. */
    private const MIN_DELKA_KMENE = 4;

    public static function zBooleovskeho(string $text): ?string
    {
        $slova = self::tokeny($text);

        if ($slova === null) {
            // Neplatné UTF-8: žádný bezpečný dotaz z toho nesložíme.
            return null;
        }

        $povinna = [];

        foreach ($slova as $male) {
            $povinna[$male] = '+'.$male.'*';
        }

        return $povinna === [] ? null : implode(' ', $povinna);
    }

    /**
     * Totéž jako `zBooleovskeho()`, jen žádné slovo není povinné.
     *
     * Druhý stupeň hledání: když se všechna slova naráz nenajdou (jedno je
     * překlep nebo tam prostě není), vrátí se aspoň to, co sedí na část —
     * a skóre `MATCH … AGAINST` dá nahoru fotky, které sedí na víc slov.
     * Slova jsou zkrácená na kmen a bez diakritiky (viz `hledanaSlova()`).
     */
    public static function zNeboDotazu(string $text): ?string
    {
        return self::zeSlov(self::hledanaSlova($text), false);
    }

    /**
     * Booleovský dotaz z už připravených slov (`hledanaSlova()`).
     *
     * @param  list<string>  $slova
     */
    public static function zeSlov(array $slova, bool $povinna): ?string
    {
        if ($slova === []) {
            return null;
        }

        return implode(' ', array_map(fn (string $slovo) => ($povinna ? '+' : '').$slovo.'*', $slova));
    }

    /**
     * Slova dotazu tak, jak se porovnávají se `search_text`.
     *
     * Malá písmena, kmen (`kmen()`) a bez diakritiky (`slozit()`). Sloupec
     * nese vedle původního textu i jeho složenou kopii (`MediaItem::hledanyText()`),
     * takže „LYSE hore" najde „Chata na Lysé hoře" na MySQL i na SQLite,
     * kde `LIKE` diakritiku ani velká písmena mimo ASCII nesjednotí.
     *
     * @return list<string>
     */
    public static function hledanaSlova(string $text): array
    {
        $vysledek = [];

        foreach (self::tokeny($text) ?? [] as $male) {
            /*
             * Po složení jen `[a-z0-9_]`.
             *
             * `Str::ascii` přepisuje některé znaky na interpunkci — `½` na
             * „1/2", modifikační písmena na apostrof nebo uvozovku. Uvozovka
             * je v booleovském režimu InnoDB operátor a neuzavřená shodí
             * dotaz na 500. Co po očištění zbude krátké, se zahodí.
             */
            $slozene = preg_replace('/[^a-z0-9_]+/', '', self::slozit(self::kmen($male))) ?? '';

            if (mb_strlen($slozene, 'UTF-8') >= self::MIN_DELKA_SLOVA) {
                $vysledek[$slozene] = true;
            }
        }

        return array_keys($vysledek);
    }

    /**
     * Lehký český kmen: utrhne jednu koncovku, když zbydou aspoň čtyři znaky.
     *
     * „výletech" → „výlet", „prahy" → „prah"; „hoře" zůstane, protože „hoř"
     * by byl moc krátký a chytal by „hořčici". Čísla se nemění.
     */
    public static function kmen(string $slovo): string
    {
        $male = mb_strtolower($slovo, 'UTF-8');

        if (preg_match('/^\p{N}+$/u', $male) === 1) {
            return $male;
        }

        $delka = mb_strlen($male, 'UTF-8');

        foreach (self::KONCOVKY as $koncovka) {
            $delkaKoncovky = mb_strlen($koncovka, 'UTF-8');

            if ($delka - $delkaKoncovky >= self::MIN_DELKA_KMENE && str_ends_with($male, $koncovka)) {
                return mb_substr($male, 0, $delka - $delkaKoncovky, 'UTF-8');
            }
        }

        return $male;
    }

    /**
     * Text bez diakritiky a malými písmeny — „Lysé hoře" → „lyse hore".
     *
     * Jedno místo pro obě strany: tak se skládá kopie v `search_text`
     * i slova dotazu. Kdyby se každá strana skládala jinak, nesešly by se.
     */
    public static function slozit(string $text): string
    {
        return mb_strtolower(Str::ascii($text), 'UTF-8');
    }

    /**
     * Slova textu, jak je dělí InnoDB, malými písmeny, bez krátkých a stopslov.
     *
     * @return list<string>|null `null` pro neplatné UTF-8
     */
    private static function tokeny(string $text): ?array
    {
        // Písmena s rozloženou diakritikou (macOS, některé klávesnice na telefonu)
        // by se jinak rozdělila v půli: „c" + „ˇ" není písmeno.
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
        }

        /*
         * Slovo tvoří písmena, značky diakritiky, číslice a `_`.
         *
         * Modifikační písmena (`\p{Lm}`: „ʼ", „ʺ") a číselné znaky mimo
         * číslice (`½`, `²`) slovo dělí: jsou to písmena jen podle Unicode,
         * člověk je píše jako apostrof, uvozovku nebo zlomek — a po složení
         * bez diakritiky by z nich interpunkce opravdu byla.
         */
        $slova = preg_split('/[^\p{Lu}\p{Ll}\p{Lt}\p{Lo}\p{M}\p{Nd}_]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($slova === false) {
            return null;
        }

        $vysledek = [];

        foreach ($slova as $slovo) {
            $male = mb_strtolower($slovo, 'UTF-8');

            if (mb_strlen($male, 'UTF-8') < self::MIN_DELKA_SLOVA || in_array($male, self::STOPSLOVA, true)) {
                continue;
            }

            $vysledek[$male] = true;
        }

        return array_keys($vysledek);
    }
}
