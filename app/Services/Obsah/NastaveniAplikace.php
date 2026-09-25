<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use App\Models\User;
use App\Support\Tabulky;
use Illuminate\Support\Facades\DB;

/**
 * Nastavení aplikace ze skutečného stavu — co se dá změnit a co o sobě ví.
 *
 * `SETROWS` v prototypu byla výstava: „Připojený Google účet ·
 * adrian@example.com", „18 402 souborů · 96 GB" v exportu, import z Google
 * Photos s „412 shodami", „Režim ticha · Zapnuto" s „14 věcmi, které ticho
 * zachytilo". U dvojice nic z toho neplatilo a polovina přepínačů nic
 * nepřepínala — hodnota se jen zapsala do stavu obrazovky.
 *
 * Tady zůstává jen to, co opravdu funguje nebo co je pravdivý údaj:
 * účet (jméno, e-mail, heslo, fotka) přes API účtu, vzhled a mřížka,
 * úložiště podle `DISK`, sdílené odkazy spočítané z databáze, zámek,
 * zařízení a export. Sekce bez funkce (import, ticho, „konec aplikace")
 * se u dvojice nenabízejí vůbec.
 *
 * Řádek je `[popisek, nápověda, hodnota nebo akce]` — akce se pozná podle
 * `SETACT` v `galerie-data.js`, přepínač podle „Zapnuto/Vypnuto", výběr
 * podle `SETOPT`. Hodnota malými písmeny je jen údaj, ne ovládací prvek.
 */
class NastaveniAplikace
{
    /**
     * @param  array<string, mixed>  $disk  výstup `System::diskAStav`
     * @param  array<string, mixed>  $zamek  výstup `System::stavZamku`
     * @param  array<string, mixed>  $mazani  výstup `System::mazani` (`MAZANI`)
     * @return array{SETROWS: array<string, mixed>, SETSEC: list<array{0: string, 1: string, 2: string}>}
     */
    public function pro(GallerySpace $prostor, ?User $ja, array $disk, array $zamek, array $mazani = []): array
    {
        return [
            'SETROWS' => [
                'profil' => $this->profil($ja),
                'galerie' => [
                    'title' => 'Galerie', 'sub' => 'Jak se knihovna zobrazuje na tomhle zařízení.',
                    'rows' => [
                        ['Výchozí velikost mřížky', 'Jak velké jsou dlaždice v knihovně', 'Střední'],
                        ['Řazení', 'Pořadí fotek v knihovně', 'Nejnovější první'],
                    ],
                ],
                'nahravani' => $this->nahravani($disk),
                'ulozeni' => $this->ulozeni($disk),
                'soukromi' => $this->soukromi($prostor),
                'zamek' => [
                    'title' => 'Zámek a přístup',
                    'sub' => 'Aplikace je jen pro vás dva. Kód, automatické zamčení a sekce, které chtějí ověření znovu.',
                    // Řádky s kódem a zařízeními doplní obrazovka ze `ZAMEK` — tam je i to, co se změnilo mezi načteními.
                    'rows' => array_values(array_filter([
                        ['Zámek při spuštění', 'Bez kódu se galerie neotevře ani na přihlášeném zařízení', 'Zapnuto'],
                        ['Automatické zamčení', 'Po jak dlouhé nečinnosti se zamkne samo', '5 min'],
                        ['Odemknutí dotykem', 'Otisk nebo obličej místo kódu, pokud je zařízení umí', 'Zapnuto'],
                        ['Zamčené sekce', 'Které sekce chtějí kód znovu, i když je aplikace odemčená', 'Deník a Finance'],
                        ['Důvěryhodná zařízení', '', ''],
                        ['Aktivní sezení', '', 'Odhlásit ostatní'],
                        ['Zamknout teď', 'Vyžádá kód okamžitě — třeba když zařízení někomu půjčíte', 'Zamknout'],
                        $this->mazani($mazani),
                    ])),
                ],
                'pwa' => [
                    'title' => 'Aplikace a zařízení', 'sub' => 'Galerie na ploše telefonu a co má u sebe uloženo.',
                    'rows' => [
                        ['Instalace aplikace', 'Ikona na ploše a vlastní okno bez adresního řádku', 'Instalovat'],
                        ['Přihlášená zařízení', (string) ($zamek['zarizeniPopis'] ?? ''), $this->pocet((int) ($zamek['zarizeni'] ?? 0), 'zařízení', 'zařízení', 'zařízení')],
                        ['Offline data a upozornění', 'Co je stažené pro chod bez signálu a jestli toto zařízení dostává upozornění', 'Nastavit'],
                        ['Vymazat lokální cache', 'Stažené náhledy a offline balíčky v tomto zařízení — znovu se načtou ze serveru', 'Vymazat'],
                    ],
                ],
                'menu' => ['title' => 'Uspořádání menu', 'sub' => 'Přeskládejte si navigaci a schovejte, co nepoužíváte.', 'rows' => []],
                'export' => $this->export($disk),
            ],
            'SETSEC' => [
                ['profil', 'Profil', 'ph-user-circle'], ['galerie', 'Galerie', 'ph-images'], ['nahravani', 'Nahrávání', 'ph-upload-simple'],
                ['ulozeni', 'Úložiště a synchronizace', 'ph-cloud'], ['soukromi', 'Soukromí a sdílení', 'ph-lock-simple'],
                ['zamek', 'Zámek a přístup', 'ph-lock-key'], ['pwa', 'Aplikace a zařízení', 'ph-device-mobile'],
                ['menu', 'Uspořádání menu', 'ph-list'], ['export', 'Export a data', 'ph-download-simple'],
            ],
        ];
    }

    /**
     * Mazání fotek — pravidlo, které si dvojice mění jen společně.
     *
     * Stav je v nápovědě, tlačítko (`SETACT`) jen to, co ten, kdo se dívá,
     * může udělat teď: navrhnout, potvrdit návrh druhého, stáhnout svůj,
     * nebo vrátit společné schvalování. Jména jsou v prvním pádě —
     * skloňovat cizí jméno aplikace neumí a „čeká na potvrzení od Adrian"
     * zní hůř než věta, ve které jméno stojí jako podmět.
     *
     * Kdo je v galerii sám, nemá s kým se dohodnout; hodnota malými písmeny
     * je jen údaj, ne tlačítko.
     *
     * @param  array<string, mixed>  $m  `MAZANI`
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function mazani(array $m): ?array
    {
        if ($m === []) {
            return null;
        }

        $partner = $m['partner'] ?? null;
        $navrh = $m['navrhRezimu'] ?? null;

        if (($m['rezim'] ?? 'spolecne') === 'kazdy') {
            return ['Mazání fotek', 'Každý maže sám — potvrdili jste to oba', 'Vrátit společné schvalování'];
        }

        if (is_array($navrh) && ($navrh['rezim'] ?? null) === 'kazdy') {
            return ! empty($navrh['ja'])
                ? ['Mazání fotek', $partner ? 'Návrh čeká, až ho potvrdí '.$partner : 'Návrh čeká na potvrzení od druhého z vás', 'Zrušit návrh']
                : ['Mazání fotek', ($navrh['kdo'] ?? 'Druhý z vás').' navrhuje, aby každý mazal sám — potvrďte kódem zámku nebo heslem', 'Potvrdit změnu'];
        }

        if ($partner === null) {
            return ['Mazání fotek', 'Mažete sami — v galerii zatím nikdo další není', 'sami'];
        }

        return ['Mazání fotek', 'Jen po společném schválení — „Do koše" fotku navrhne, smaže ji až souhlas druhého', 'Navrhnout mazání bez schválení'];
    }

    /** @return array<string, mixed> */
    private function profil(?User $ja): array
    {
        $dvaFaktory = $ja !== null && Tabulky::sloupec('users', 'two_factor_confirmed_at') && $ja->two_factor_confirmed_at !== null;
        $fotka = $ja !== null && (! empty($ja->avatar_path) || (Tabulky::sloupec('users', 'avatar_preset') && ! empty($ja->avatar_preset)));

        return [
            'title' => 'Profil', 'sub' => 'Jak vás galerie zná a čím se do ní přihlašujete.',
            'rows' => [
                ['Jméno a e-mail', $ja ? trim($ja->name.' · '.$ja->email) : '', 'Upravit'],
                ['Profilová fotografie', $fotka ? 'Vlastní obrázek u vašeho jména' : 'Zatím iniciála na barevném kolečku', 'Změnit'],
                ['Heslo', 'S ním se přihlašujete a odemykáte trezor', 'Změnit heslo'],
                // Třetí pole je tlačítko (SETACT v galerie-data.js): stav říká popis.
                ['Dvoufázové přihlášení', $dvaFaktory
                    ? 'Zapnuto — při přihlášení se chce i kód z ověřovací aplikace'
                    : 'Vypnuto — přihlášení chce jen heslo', $dvaFaktory ? 'Vypnout ověření' : 'Zapnout ověření'],
                ['Výchozí vzhled', 'Světlý, tmavý nebo podle systému', 'Podle systému'],
            ],
        ];
    }

    /** @param array<string, mixed> $disk
     * @return array<string, mixed> */
    private function nahravani(array $disk): array
    {
        $limit = (int) config('gallery.max_upload_size_gb', 32);

        return [
            'title' => 'Nahrávání', 'sub' => 'Co se stane, když přidáte nové soubory.',
            'rows' => [
                ['Stejný soubor podruhé', 'Pozná se podle otisku obsahu a znovu se nenahraje — hlášení řekne, kolik jich v knihovně už bylo', 'přeskočí se'],
                ['Datum, poloha a fotoaparát', 'Čtou se z fotky a zůstávají u ní', 'zachovají se'],
                ['Největší soubor', 'Větší video se nahraje po částech, přes tuhle mez ne', 'do '.$limit.' GB'],
                ['Kam jdou originály', ! empty($disk['connected'])
                    ? 'Po nahrání se zkopírují na Google Disk '.($disk['account'] ?? '')
                    : 'Zůstávají jen na serveru galerie — Google Disk není připojený', ! empty($disk['connected']) ? 'google disk' : 'jen server'],
            ],
        ];
    }

    /** @param array<string, mixed> $disk
     * @return array<string, mixed> */
    private function ulozeni(array $disk): array
    {
        $objemy = implode(' · ', array_map(fn (array $c) => (string) $c['label'], (array) ($disk['capacity'] ?? [])));
        $chyb = (int) ($disk['failed'] ?? 0);

        return [
            'title' => 'Úložiště a synchronizace', 'sub' => 'Kde leží originály a jak se tam dostávají.',
            'rows' => [
                ['Google Disk', (string) ($disk['headline'] ?? 'Stav není známý'), 'Detail'],
                ['Využití prostoru', $objemy, 'Detail'],
                $chyb > 0
                    ? ['Chyby zpracování', (string) ($disk['failedTitle'] ?? ''), 'Zkusit znovu']
                    : ['Chyby zpracování', 'Všechno nahrané se zpracovalo', 'žádné'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function soukromi(GallerySpace $prostor): array
    {
        $aktivni = 0;
        $neplatne = 0;

        if (Tabulky::je('shared_links')) {
            $odkazy = DB::table('shared_links')->where('gallery_space_id', $prostor->id)->get(['is_active', 'expires_at']);
            $aktivni = $odkazy->filter(fn (object $o) => $o->is_active && ($o->expires_at === null || now()->lt($o->expires_at)))->count();
            $neplatne = $odkazy->count() - $aktivni;
        }

        return [
            'title' => 'Soukromí a sdílení', 'sub' => 'Odkazy, které jste poslali dál, a kdo co v galerii změnil.',
            'rows' => [
                ['Sdílené odkazy', $aktivni + $neplatne === 0
                    ? 'Zatím žádný — odkaz vznikne ze sdílení fotky nebo alba'
                    : $this->pocet($aktivni, 'funguje', 'fungují', 'funguje')
                        .($neplatne > 0 ? ' · '.$this->pocet($neplatne, 'vypršel nebo je vypnutý', 'vypršely nebo jsou vypnuté', 'vypršelo nebo je vypnutých') : ''), 'Spravovat'],
                ['Historie změn', 'Kdo co nahrál, sdílel nebo obnovil z koše', 'Zobrazit'],
            ],
        ];
    }

    /** @param array<string, mixed> $disk
     * @return array<string, mixed> */
    private function export(array $disk): array
    {
        return [
            'title' => 'Export a data', 'sub' => 'Co je v aplikaci, si můžete odnést — bez čekání na podporu.',
            'rows' => [
                ['Historie změn', 'Kdo co změnil napříč celou aplikací', 'Zobrazit'],
                ['Kalendář jako iCal', 'Události včetně opakování — načtete je do Google nebo Apple kalendáře', 'Stáhnout .ics'],
                ['Deník jako text', 'Zápisy od nejnovějšího, s daty', 'Stáhnout .txt'],
                ['Rozpočty a transakce', 'CSV pro tabulkový editor — kategorie, částky, účty', 'Stáhnout .csv'],
                ['Úkoly a nástěnky', 'Úkoly se sloupci a termíny', 'Stáhnout .csv'],
                ['Fotky a videa', ! empty($disk['connected'])
                    ? 'Originály leží na Google Disku '.($disk['account'] ?? '').' — celý archiv stáhnete přímo z Disku, vybrané fotky jako ZIP z výběru v knihovně'
                    : 'Vybrané fotky stáhnete jako ZIP z výběru v knihovně', 'Otevřít úložiště'],
            ],
        ];
    }

    private function pocet(int $kolik, string $jeden, string $dva, string $pet): string
    {
        return $kolik.' '.($kolik === 1 ? $jeden : ($kolik >= 2 && $kolik <= 4 ? $dva : $pet));
    }
}
