<?php

namespace App\Support;

/**
 * Adresy obrazovek prototypu.
 *
 * Prototyp měl všech padesát šest obrazovek na jediné adrese `/`. Nikam se
 * nedalo odkázat, nic se nedalo přidat do oblíbených, obnovení stránky vrátilo
 * dvojici na úvod a tlačítko Zpět v prohlížeči zavřelo celou aplikaci — jediné
 * `history.replaceState` v dokumentu totiž adresu jen uklízelo, nikdy ji
 * neměnilo podle toho, co je na obrazovce.
 *
 * Tenhle seznam je jediný zdroj pravdy pro obě strany: server podle něj přijme
 * `/galerie/<kousek>` a prohlížeč podle téhož seznamu adresu při přepnutí
 * obrazovky přepíše. Kdyby to byly dva seznamy, rozešly by se — a odkaz poslaný
 * partnerovi by otevřel jinou obrazovku, než jakou měl odesílatel před sebou.
 *
 * Klíč je trasa tak, jak ji zná stav prohlížeče (`state.route`); hodnota je
 * kousek adresy. Prázdná hodnota patří úvodní obrazovce, tedy `/galerie`.
 * Názvy tras začínající `x-` jsou dědictví prototypu a do adresy se nepíšou.
 */
final class TrasyPrototypu
{
    /** Předpona, pod kterou aplikace běží. */
    public const ZAKLAD = 'galerie';

    /** @var array<string, string> trasa prohlížeče => kousek adresy */
    public const ADRESY = [
        'home' => '',

        // Prohlížení
        'timeline' => 'casova-osa',
        'albums' => 'alba',
        'map' => 'mapa',
        'calendar' => 'kalendar',

        // Objevovat
        'all' => 'knihovna',
        'favorites' => 'oblibene',
        'x-lide' => 'lide',
        'x-tagy' => 'tagy',
        'x-vzpominky' => 'vzpominky',
        'x-vybery' => 'spolecne-vybery',
        'x-vyrocni' => 'vyrocni-album',
        'x-kapsle' => 'casova-kapsle',
        'x-zprava' => 'vyrocni-zprava',
        'x-statistiky' => 'statistiky',
        'x-pribeh' => 'nas-pribeh',

        // Výstupy
        'x-promitani' => 'promitani',
        'x-tisk' => 'tisk-a-fotoknihy',
        'x-uklid' => 'uklid-knihovny',

        // Společný život
        'x-zpravy' => 'zpravy',
        'x-darky' => 'darky',
        'x-domacnost' => 'domacnost',
        'x-klid' => 'klid-a-pohoda',
        'x-nedele' => 'nedelni-deset-minut',
        'x-rozhodnuti' => 'rozhodnuti',
        'x-tyden' => 'tydenni-prehled',
        'x-plan' => 'planovani',
        'x-denik' => 'denik',
        'x-pravidla' => 'pravidla',
        'x-milniky' => 'milniky',
        'x-randicka' => 'randicka',
        'x-filmy' => 'filmy',
        'x-kucharka' => 'kucharka',
        'x-kolo' => 'rozhodovaci-kolecko',
        'x-zadosti' => 'zadosti',
        'x-ritualy' => 'ritualy',
        'x-cyklus' => 'cyklus',

        // Cestování. `x-teď` je jediná trasa s diakritikou — do adresy by se
        // zakódovala jako `x-te%C4%8F`, což není pěkná adresa nikde.
        'x-teď' => 'cesta-nyni',
        'x-cesty' => 'cesty',
        'x-mista' => 'mista',
        'x-svet' => 'svetovy-itinerar',
        'x-inbox-cesty' => 'inbox-cest',

        // Finance
        'x-finance' => 'finance',
        'x-transakce' => 'transakce',
        'x-rozpocty' => 'rozpocty',
        'x-naklady' => 'co-to-znamena',
        'x-ucty' => 'ucty',

        // Sdílení
        'shared' => 'sdilene',
        'x-inbox' => 'inbox',
        'x-trezor' => 'trezor',
        'x-offline' => 'offline',
        'x-zmeny' => 'zmeny',

        // Systém
        'activity' => 'aktivita',
        'trash' => 'kos',
        'storage' => 'uloziste',
        'settings' => 'nastaveni',
        'x-admin' => 'administrace',
    ];

    /** Kousek adresy => trasa. `null` = takovou adresu neznáme. */
    public static function trasa(?string $adresa): ?string
    {
        $adresa = trim((string) $adresa, '/');

        if ($adresa === '') {
            return 'home';
        }

        return array_search($adresa, self::ADRESY, true) ?: null;
    }

    /** Trasa => kousek adresy. `null` = trasa bez vlastní adresy (detaily). */
    public static function adresa(string $trasa): ?string
    {
        return self::ADRESY[$trasa] ?? null;
    }

    /** Celá cesta pro trasu, tedy `/galerie` nebo `/galerie/alba`. */
    public static function cesta(string $trasa): string
    {
        $kousek = self::adresa($trasa);

        return '/'.self::ZAKLAD.($kousek === null || $kousek === '' ? '' : '/'.$kousek);
    }

    /**
     * Pro prohlížeč: trasa => kousek adresy.
     *
     * Posílá se celá mapa, ne jen ta jedna trasa: prohlížeč adresu přepisuje
     * při každém přepnutí obrazovky, takže potřebuje znát všechny.
     *
     * @return array<string, string>
     */
    public static function proKlienta(): array
    {
        return self::ADRESY;
    }
}
