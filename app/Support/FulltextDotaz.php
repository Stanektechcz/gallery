<?php

namespace App\Support;

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

    public static function zBooleovskeho(string $text): ?string
    {
        // Písmena s rozloženou diakritikou (macOS, některé klávesnice na telefonu)
        // by se jinak rozdělila v půli: „c" + „ˇ" není písmeno.
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
        }

        $slova = preg_split('/[^\p{L}\p{M}\p{N}_]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($slova === false) {
            // Neplatné UTF-8: žádný bezpečný dotaz z toho nesložíme.
            return null;
        }

        $povinna = [];

        foreach ($slova as $slovo) {
            $male = mb_strtolower($slovo, 'UTF-8');

            if (mb_strlen($male, 'UTF-8') < self::MIN_DELKA_SLOVA || in_array($male, self::STOPSLOVA, true)) {
                continue;
            }

            $povinna[$male] = '+'.$male.'*';
        }

        return $povinna === [] ? null : implode(' ', $povinna);
    }
}
