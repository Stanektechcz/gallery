<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Auth\PristupDoGalerie;
use App\Services\Obsah\Darky;
use App\Support\Tabulky;
use App\Support\Vejde;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Přání, nápady a chystané dárky, které přišly jako změna stavu.
 *
 * Všechny tři obrazovky čtou `state.wishes || GIFT_WISHES` (a stejně tak
 * `ideas` a `buys`), takže první napsané přání zastínilo celou sekci: druhý
 * z dvojice o něm nevěděl, po zavření záložky zmizelo a skutečné dárky
 * z obrazovky vypadly.
 *
 * Celá sekce přitom stojí na jedné věci: **druhý nesmí vidět, co se pro něj
 * chystá.** Zápis to drží stejně jako čtení — nákup dostane
 * `private_to_user_id` toho, kdo ho pořizuje.
 */
class DarkyVeStavu
{
    /** Klíče, které patří databázi. Do stavu se neukládají. */
    public const SERVEROVE = ['wishes', 'ideas', 'buys'];

    public function __construct(private readonly Darky $obsah) {}

    public function tykaSe(array $patch): bool
    {
        foreach (self::SERVEROVE as $klic) {
            if (array_key_exists($klic, $patch)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function bezDarku(array $patch): array
    {
        return array_diff_key($patch, array_flip(self::SERVEROVE));
    }

    /**
     * Zapíše a vrátí sekci tak, jak ji zná server.
     *
     * @return array<string, mixed>
     */
    public function zpracuj(array $patch, GallerySpace $prostor, ?User $uzivatel): array
    {
        if ($uzivatel === null || ! Tabulky::je('gift_ideas')) {
            return [];
        }

        /*
         * Jméno → člověk jen ze dvojice.
         *
         * Ze všech členů se do mapy dostal i host: přání podepsané jeho
         * jménem se mu připsalo a nákup „jeho" dostal jeho soukromí — host
         * by pak viděl, co se v prostoru chystá.
         */
        $jmena = app(PristupDoGalerie::class)->dvojice($prostor)
            ->mapWithKeys(fn (User $clen) => [(string) $clen->name => (int) $clen->id])
            ->all();
        $zustavaji = [];
        $tykaSe = [];

        foreach (['wishes' => 'prani', 'ideas' => 'napad', 'buys' => 'nakup'] as $klic => $druh) {
            // Jen skutečný seznam: vynulovaná místní kopie (`null`) nesmaže všechna přání.
            if (! is_array($patch[$klic] ?? null)) {
                continue;
            }

            $tykaSe[] = $druh;

            $zmenene = OdebraneVStavu::zmenene($patch, $klic);

            foreach ((array) $patch[$klic] as $polozka) {
                $id = $this->uloz((array) $polozka, $druh, $prostor, $uzivatel, $jmena, $zmenene);

                if ($id !== null) {
                    $zustavaji[] = $id;
                }
            }
        }

        $odebrane = [];

        foreach (['wishes', 'ideas', 'buys'] as $klic) {
            if (! is_array($patch[$klic] ?? null)) {
                continue;
            }

            $zKlice = OdebraneVStavu::pro($patch, $klic);

            if ($zKlice === null) {
                $odebrane = null;
                break;
            }

            $odebrane = array_merge($odebrane, $zKlice);
        }

        $this->smazChybejici($zustavaji, $tykaSe, $prostor, $uzivatel, $odebrane);

        $obsah = $this->obsah->kolekce($prostor);

        return array_filter([
            'wishes' => $obsah['GIFT_WISHES'] ?? [],
            'ideas' => $obsah['GIFT_IDEAS'] ?? [],
            'buys' => $obsah['GIFT_BUYS'] ?? [],
        ], fn ($v) => $v !== []);
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, int>  $jmena
     * @return int|null id řádku, který má zůstat
     */
    private function uloz(array $p, string $druh, GallerySpace $prostor, User $uzivatel, array $jmena, ?array $zmenene = null): ?int
    {
        $nazev = trim((string) ($p['title'] ?? $p['what'] ?? ''));

        if ($nazev === '') {
            return null;
        }

        $idKlienta = is_scalar($p['id'] ?? null) ? (string) $p['id'] : '';
        $maSoukromi = Tabulky::sloupec('gift_ideas', 'private_to_user_id');

        /*
         * Jen řádek, který ten člověk smí vidět (stejně jako `Darky::polozky()`).
         *
         * Uuid cizího schovaného dárku poslané v seznamu by jinak řádek našlo
         * a přepsalo mu vlastníka — dárek by se prozradil.
         */
        $existujici = $idKlienta === '' ? null : DB::table('gift_ideas')
            ->where('gallery_space_id', $prostor->id)
            ->where('uuid', $idKlienta)
            ->when($maSoukromi, fn ($q) => $q->where(
                fn ($v) => $v->whereNull('private_to_user_id')->orWhere('private_to_user_id', $uzivatel->id),
            ))
            ->first();

        /*
         * Uuid vydal server — když řádek není, někdo ho mezitím smazal.
         *
         * Starší opis seznamu (karta otevřená od rána, druhé zařízení) ho
         * posílá dál a dřív se tu založil znovu: smazané přání vstalo.
         * Nový řádek z obrazovky má vlastní identifikátor (`w1757…`).
         */
        if ($existujici === null && Str::isUuid($idKlienta)) {
            return null;
        }

        // Nezměněná položka se nepřepisuje — v prohlížeči může být starší opis
        // toho, co mezitím upravil ten druhý (viz OdebraneVStavu::zmenene()).
        if ($existujici !== null && ! OdebraneVStavu::zmeneno($zmenene, $idKlienta)) {
            return (int) $existujici->id;
        }

        $radek = [
            'title' => Vejde::do($nazev),
            'budget' => (int) ($p['price'] ?? 0),
            'source_url' => Vejde::do($p['note'] ?? $p['text'] ?? $p['where'] ?? ''),
            'status' => $this->stav($p, $druh),
            'occasion' => Vejde::do($p['occasion'] ?? '', 80),
            'updated_at' => now(),
        ];

        /*
         * Přání si píše člověk sám za sebe a je veřejné — o to jde. Nákup je
         * naopak soukromý toho, kdo ho pořizuje; prozrazený dárek se nedá
         * vzít zpět.
         */
        $pojmenovany = $jmena[(string) ($p['who'] ?? $p['owner'] ?? '')] ?? null;
        $autor = $pojmenovany ?? $uzivatel->id;

        $radek += $this->soukromi($druh, $autor, $existujici, $maSoukromi);

        // Přání i nákup svého člověka jmenují — a ten se může změnit, když se
        // nápad povýší na přání toho druhého. Nápad nikoho nejmenuje, tam
        // autor zůstává, jak byl.
        if ($pojmenovany !== null) {
            $radek['created_by'] = $pojmenovany;
        }

        if ($existujici !== null) {
            DB::table('gift_ideas')->where('id', $existujici->id)->update($radek);

            return (int) $existujici->id;
        }

        return (int) DB::table('gift_ideas')->insertGetId($radek + [
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $prostor->id,
            'created_by' => $autor,
            'currency' => 'CZK',
            'created_at' => now(),
        ]);
    }

    /**
     * Soukromí řádku — oba sloupce najednou.
     *
     * Čtení se dělí: `Darky` a úklid se ptají na `private_to_user_id`,
     * kalendář dárků, rozpočty a koordinace dvojice na `visibility`. Když
     * se psal jen první, nákup měl `visibility = shared` (výchozí hodnota)
     * a v `/api/v1/calendar/gifts` ho druhý viděl.
     *
     * Nákup je soukromý toho, kdo ho pořizuje; přání je veřejné (o to jde).
     * Nápad se nepřepisuje: může to být soukromý nápad z kalendáře a jeho
     * přejmenování v prototypu ho nesmí zveřejnit. Nový nápad je společný.
     *
     * @return array<string, mixed>
     */
    private function soukromi(string $druh, int $autor, ?object $existujici, bool $maSoukromi): array
    {
        if (! $maSoukromi || ($druh === 'napad' && $existujici !== null)) {
            return [];
        }

        $soukromy = $druh === 'nakup';
        $radek = ['private_to_user_id' => $soukromy ? $autor : null];

        if (Tabulky::sloupec('gift_ideas', 'visibility')) {
            $radek['visibility'] = $soukromy ? 'private' : 'shared';
        }

        return $radek;
    }

    /**
     * Co v seznamu není, dvojice smazala.
     *
     * **Jen z toho, co ten člověk vidí.** Nákup, který si druhý schoval sám
     * pro sebe, v jeho seznamu není — a smazat cizí schovaný dárek proto, že
     * o něm ten první neví, by bylo to nejhorší, co tahle vrstva může udělat.
     *
     * A jen to, co prohlížeč výslovně odebral (viz OdebraneVStavu) — přání,
     * které mezitím napsal ten druhý, v jeho seznamu chybí taky.
     *
     * @param  list<int>  $zustavaji
     * @param  list<string>  $druhy
     * @param  list<string>|null  $odebrane
     */
    private function smazChybejici(array $zustavaji, array $druhy, GallerySpace $prostor, User $uzivatel, ?array $odebrane): void
    {
        if ($druhy === []) {
            return;
        }

        $stavy = [];

        foreach ($druhy as $druh) {
            $stavy = array_merge($stavy, match ($druh) {
                'prani' => ['wish', 'wanted', 'prani'],
                'napad' => ['idea', ''],
                default => ['reserved', 'planned', 'bought', 'purchased', 'wrapped', 'given', 'done'],
            });
        }

        if (! OdebraneVStavu::smiMazat($odebrane, $zustavaji)) {
            return;
        }

        DB::table('gift_ideas')
            ->where('gallery_space_id', $prostor->id)
            ->whereIn('status', $stavy)
            ->when(
                Tabulky::sloupec('gift_ideas', 'private_to_user_id'),
                fn ($q) => $q->where(
                    fn ($v) => $v->whereNull('private_to_user_id')->orWhere('private_to_user_id', $uzivatel->id),
                ),
            )
            ->when($zustavaji !== [], fn ($q) => $q->whereNotIn('id', $zustavaji))
            ->when($odebrane !== null, fn ($q) => $q->whereIn('uuid', $odebrane ?: ['']))
            ->delete();
    }

    /** @param  array<string, mixed>  $p */
    private function stav(array $p, string $druh): string
    {
        if ($druh === 'prani') {
            return 'wish';
        }

        if ($druh === 'napad') {
            return 'idea';
        }

        return match ((string) ($p['status'] ?? 'reserved')) {
            'bought' => 'bought',
            'wrapped' => 'wrapped',
            'given' => 'given',
            default => 'reserved',
        };
    }
}
