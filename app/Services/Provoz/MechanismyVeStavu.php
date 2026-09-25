<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Auth\PristupDoGalerie;
use App\Services\Obsah\Mechanismy;
use App\Support\Cas;
use App\Support\Tabulky;
use App\Support\Vejde;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mechanismy pro dva, které přišly jako změna stavu.
 *
 * Vyrovnaná laskavost, odpuštěná věc, zrušené předplatné, kontakt s rodinou —
 * všechno to končilo v jednom JSON dokumentu. Nebylo to ztracené, ale nešlo se
 * na to zeptat: kolik laskavostí je nevyrovnaných, se z blobu nedozví ani
 * upozornění, ani týdenní přehled.
 */
class MechanismyVeStavu
{
    /**
     * Klíče, které patří databázi. Do stavu se neukládají.
     *
     * `mlLoad`, `pauseLog` a `pausePlan` tu dřív byly taky — jenže převodník
     * je nikam nezapisoval. Kontroler je proto ze zápisu vyhodil **a** ze
     * stavu smazal (`zapomen()`), takže mentální zátěž, pravidla pauzy
     * i historie pauz zmizely hned po uložení. Do tabulek je zatím zapsat
     * nejde bezpečně: řádky nemají identifikátor a „kdy" v historii je věta
     * („4. září"), takže by se dal jen přepsat celý seznam — a to je přesně
     * to, co starší opis v kartě smazat nesmí (viz OdebraneVStavu). Zůstávají
     * proto ve sdíleném stavu dvojice, kam je prototyp ukládá.
     */
    public const SERVEROVE = ['favList', 'forgList', 'antiList', 'fam', 'truths'];

    /** Tabulka → klíč seznamu v prohlížeči (kvůli tomu, co z něj výslovně odebral). */
    private const KLICE = [
        'couple_favours' => 'favList',
        'couple_forgiven' => 'forgList',
        'couple_anti_budget' => 'antiList',
        'couple_family_contacts' => 'fam',
        /*
         * `truths` tu chyběl a stálo to obě pojistky naráz.
         *
         * `srovnej()` se bez záznamu ptá `OdebraneVStavu` na klíč jménem
         * tabulky, tedy `couple_truths` — zatímco prohlížeč posílá `truths`.
         * `zmenene()` proto vracel `null` (= přepiš každý řádek, i ten, co
         * mezitím napsal ten druhý) a `pro()` prázdný seznam nebo `null`.
         * V druhém případě zbylo v mazání jen `whereNotIn`, takže přidání
         * jedné „dvě pravdy" smazalo všechny, které partner zapsal od chvíle,
         * kdy se karta načetla.
         */
        'couple_truths' => 'truths',
    ];

    /** Právě zpracovávaný patch — nese `__odebrane` (viz OdebraneVStavu). */
    private array $patch = [];

    public function __construct(private readonly Mechanismy $obsah) {}

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
    public function bezMechanismu(array $patch): array
    {
        return array_diff_key($patch, array_flip(self::SERVEROVE));
    }

    /** @return array<string, mixed> */
    public function zpracuj(array $patch, GallerySpace $prostor, ?User $uzivatel): array
    {
        // Jen dvojice: laskavost „od hosta" nebo host jako strana rodiny by
        // v účtu dvojice neměly co dělat.
        $lide = app(PristupDoGalerie::class)->dvojice($prostor)
            ->mapWithKeys(fn ($clen) => [(string) $clen->name => (int) $clen->id])->all();
        $this->patch = $patch;

        /*
         * Jen skutečný seznam, ne `null`.
         *
         * Klient místní kopii vynuluje (`fam: null`), aby znovu četl ze
         * serveru; `(array) null` je prázdný seznam a převodník ho bral jako
         * „všechno odebráno" — smazal všechny kontakty z rodiny.
         */
        if (is_array($patch['favList'] ?? null)) {
            $this->laskavosti($patch['favList'], $prostor, $lide);
        }

        if (is_array($patch['forgList'] ?? null)) {
            $this->odpustene($patch['forgList'], $prostor, $lide);
        }

        if (is_array($patch['antiList'] ?? null)) {
            $this->antiRozpocet($patch['antiList'], $prostor);
        }

        if (is_array($patch['fam'] ?? null)) {
            $this->rodina($patch['fam'], $prostor, $lide);
        }

        if (is_array($patch['truths'] ?? null)) {
            $this->pravdy($patch['truths'], $prostor);
        }

        $obsah = $this->obsah->kolekce($prostor);

        return array_filter([
            'favList' => $obsah['FAV'] ?? [],
            'forgList' => $obsah['FORGIVEN'] ?? [],
            'antiList' => $obsah['ANTI'] ?? [],
            'fam' => $obsah['FAMILY'] ?? [],
            'truths' => $obsah['TRUTHS'] ?? [],
        ], fn ($v) => $v !== []);
    }

    /**
     * Účet laskavostí. Vyrovnat laskavost znamená přepnout příznak, ne smazat
     * řádek — účet je o tom, co se stalo, ne o tom, co ještě visí.
     *
     * @param  list<mixed>  $seznam
     * @param  array<string, int>  $lide
     */
    private function laskavosti(array $seznam, GallerySpace $prostor, array $lide): void
    {
        if (! Tabulky::je('couple_favours')) {
            return;
        }

        $this->srovnej('couple_favours', $seznam, $prostor, function (array $l) use ($lide) {
            $co = Vejde::do($l['what'] ?? '');

            return $co === '' ? null : [
                'from_user_id' => $this->kdo($lide, $l['from'] ?? null),
                'what' => $co,
                'happened_on' => $this->den($l['date'] ?? null),
                'weight' => max(1, min(5, (int) ($l['w'] ?? 1))),
                'is_settled' => (bool) ($l['settled'] ?? false),
            ];
        });
    }

    /**
     * @param  list<mixed>  $seznam
     * @param  array<string, int>  $lide
     */
    private function odpustene(array $seznam, GallerySpace $prostor, array $lide): void
    {
        if (! Tabulky::je('couple_forgiven')) {
            return;
        }

        $this->srovnej('couple_forgiven', $seznam, $prostor, function (array $o) use ($lide) {
            $co = Vejde::do($o['what'] ?? '');

            return $co === '' ? null : [
                'forgiven_by' => $this->kdo($lide, $o['by'] ?? null),
                'what' => $co,
                'happened_on' => $this->den($o['date'] ?? null),
                'tries' => Vejde::cislo($o['tries'] ?? 0, 0, Vejde::SMALL),
            ];
        });
    }

    /**
     * Anti-rozpočet. Měsíc je slovo, ne datum — ukládá se první den toho
     * měsíce v roce, ve kterém se to rozhodlo.
     *
     * @param  list<mixed>  $seznam
     */
    private function antiRozpocet(array $seznam, GallerySpace $prostor): void
    {
        if (! Tabulky::je('couple_anti_budget')) {
            return;
        }

        $mesice = array_flip([1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen',
            'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec']);

        $this->srovnej('couple_anti_budget', $seznam, $prostor, function (array $a) use ($mesice) {
            $nazev = Vejde::do($a['name'] ?? '');

            if ($nazev === '') {
                return null;
            }

            $mesic = $mesice[Vejde::do($a['month'] ?? '')] ?? Cas::dnes()->month;

            return [
                'name' => $nazev,
                'kind' => $this->druhAnti(Vejde::do($a['type'] ?? '')),
                'saved' => Vejde::cislo($a['saved'] ?? 0, 0, Vejde::INT),
                'decided_on' => Cas::dnes()->setDate(Cas::dnes()->year, $mesic, 1)->toDateString(),
                'came_back' => (bool) ($a['back'] ?? false),
            ];
        });
    }

    /**
     * Rotace kontaktu s rodinou.
     *
     * `last` je věta („před 4 dny"), ne datum — z ní se datum nedopočítává:
     * kdo se ozval, ví server z toho, co mu přišlo předtím, a přepsat to
     * přibližným výpočtem by rotaci posunulo při každém načtení.
     *
     * @param  list<mixed>  $seznam
     * @param  array<string, int>  $lide
     */
    private function rodina(array $seznam, GallerySpace $prostor, array $lide): void
    {
        if (! Tabulky::je('couple_family_contacts')) {
            return;
        }

        $znamé = DB::table('couple_family_contacts')
            ->where('gallery_space_id', $prostor->id)
            ->get()
            ->keyBy('uuid');

        $zustavaji = [];

        foreach (array_values($seznam) as $poradi => $r) {
            $r = (array) $r;
            $jmeno = Vejde::do($r['name'] ?? '');

            if ($jmeno === '') {
                continue;
            }

            $uuid = Vejde::do($r['id'] ?? '');
            $puvodni = $znamé[$uuid] ?? null;
            $kdo = $this->kdo($lide, $r['lastWho'] ?? null);

            $radek = [
                'name' => $jmeno,
                'side_user_id' => $this->kdo($lide, $r['side'] ?? null),
                // `unsignedSmallInteger`; obří číslo by na MySQL shodilo zápis.
                'every_days' => Vejde::cislo($r['every'] ?? 7, 1, Vejde::SMALL),
                'note' => Vejde::do($r['note'] ?? '', 500),
                'sort_order' => min($poradi, Vejde::SMALL),
                'updated_at' => now(),
            ];

            /*
             * „Ozvali jsme se" je jediná změna, která posouvá datum.
             *
             * Pozná se podle toho, že se změnil ten, kdo byl poslední —
             * jinak by každé načtení obrazovky vypadalo jako nový kontakt.
             */
            if ($puvodni !== null && $kdo !== null && (int) $puvodni->last_contact_by !== $kdo) {
                $radek['last_contact_on'] = Cas::dnes()->toDateString();
                $radek['last_contact_by'] = $kdo;
            }

            if ($puvodni !== null) {
                // Nezměněný kontakt se nepřepisuje starším opisem (viz OdebraneVStavu::zmenene()).
                if (OdebraneVStavu::zmeneno(OdebraneVStavu::zmenene($this->patch, 'fam'), $uuid)) {
                    DB::table('couple_family_contacts')->where('id', $puvodni->id)->update($radek);
                }
                $zustavaji[] = $puvodni->id;

                continue;
            }

            $zustavaji[] = DB::table('couple_family_contacts')->insertGetId($radek + [
                'uuid' => (string) Str::uuid(),
                'gallery_space_id' => $prostor->id,
                'last_contact_on' => $kdo ? Cas::dnes()->toDateString() : null,
                'last_contact_by' => $kdo,
                'created_at' => now(),
            ]);
        }

        $odebrane = OdebraneVStavu::pro($this->patch, self::KLICE['couple_family_contacts']);

        if (! OdebraneVStavu::smiMazat($odebrane, $zustavaji)) {
            return;
        }

        DB::table('couple_family_contacts')
            ->where('gallery_space_id', $prostor->id)
            ->when($zustavaji !== [], fn ($q) => $q->whereNotIn('id', $zustavaji))
            ->when($odebrane !== null, fn ($q) => $q->whereIn('uuid', $odebrane ?: ['']))
            ->delete();
    }

    /**
     * Dvě pravdy o jedné události — obě verze vedle sebe, žádná nevyhrává.
     *
     * @param  list<mixed>  $seznam
     */
    private function pravdy(array $seznam, GallerySpace $prostor): void
    {
        if (! Tabulky::je('couple_truths')) {
            return;
        }

        // `a` z obrazovky je verze toho, kdo píše; `m` toho druhého.
        $ja = (int) (auth()->id() ?? $prostor->owner_id);
        // Jen dvojice. Se všemi členy mohl host s nižším id skončit jako
        // „ten druhý" a verze partnera by se podepsala jemu.
        $lide = array_map('intval', app(PristupDoGalerie::class)->dvojice($prostor)->pluck('id')->all());
        usort($lide, fn (int $x, int $y) => [$x !== $ja, $x] <=> [$y !== $ja, $y]);
        [$prvni, $druhy] = array_pad($lide, 2, null);

        $this->srovnej('couple_truths', $seznam, $prostor, function (array $p) use ($prvni, $druhy) {
            $nadpis = Vejde::do($p['title'] ?? '');

            return $nadpis === '' ? null : [
                'title' => $nadpis,
                'context' => Vejde::do($p['when'] ?? ''),
                'first_user_id' => $prvni,
                'first_version' => Vejde::do($p['a'] ?? ''),
                'second_user_id' => $druhy,
                'second_version' => Vejde::do($p['m'] ?? ''),
            ];
        });
    }

    /**
     * Srovná seznam z obrazovky s tabulkou: co je, přepíše; co není, smaže.
     *
     * @param  list<mixed>  $seznam
     * @param  callable(array<string, mixed>): (array<string, mixed>|null)  $prevod
     */
    private function srovnej(string $tabulka, array $seznam, GallerySpace $prostor, callable $prevod): void
    {
        $znamé = DB::table($tabulka)
            ->where('gallery_space_id', $prostor->id)
            ->pluck('id', 'uuid');

        $zustavaji = [];

        foreach ($seznam as $polozka) {
            $polozka = (array) $polozka;
            $radek = $prevod($polozka);

            if ($radek === null) {
                continue;
            }

            $uuid = Vejde::do($polozka['id'] ?? '');

            if (isset($znamé[$uuid])) {
                if (OdebraneVStavu::zmeneno(OdebraneVStavu::zmenene($this->patch, self::KLICE[$tabulka] ?? $tabulka), $uuid)) {
                    DB::table($tabulka)->where('id', $znamé[$uuid])->update($radek + ['updated_at' => now()]);
                }
                $zustavaji[] = $znamé[$uuid];

                continue;
            }

            $zustavaji[] = DB::table($tabulka)->insertGetId($radek + [
                'uuid' => (string) Str::uuid(),
                'gallery_space_id' => $prostor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Jen co prohlížeč sám odebral — položka druhého v jeho seznamu chybí taky.
        $odebrane = OdebraneVStavu::pro($this->patch, self::KLICE[$tabulka] ?? $tabulka);

        if (! OdebraneVStavu::smiMazat($odebrane, $zustavaji)) {
            return;
        }

        DB::table($tabulka)
            ->where('gallery_space_id', $prostor->id)
            ->when($zustavaji !== [], fn ($q) => $q->whereNotIn('id', $zustavaji))
            ->when($odebrane !== null, fn ($q) => $q->whereIn('uuid', $odebrane ?: ['']))
            ->delete();
    }

    /** Skutečné datum, jinak dnešek — tvar sám nestačí („2026-13-45" MySQL odmítne). */
    private function den(mixed $hodnota): string
    {
        return Vejde::den($hodnota) ?? Cas::dnes()->toDateString();
    }

    /**
     * Člen dvojice podle jména z obrazovky.
     *
     * @param  array<string, int>  $lide
     */
    private function kdo(array $lide, mixed $jmeno): ?int
    {
        return is_scalar($jmeno) ? ($lide[(string) $jmeno] ?? null) : null;
    }

    private function druhAnti(string $druh): string
    {
        return match ($druh) {
            'předplatné' => 'predplatne',
            'věc' => 'vec',
            'služba' => 'sluzba',
            default => 'jine',
        };
    }
}
