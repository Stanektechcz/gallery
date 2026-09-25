<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Obsah\Klid;
use App\Support\Cas;
use App\Support\Tabulky;
use App\Support\Vejde;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Klid a pohoda, který přišel jako změna stavu.
 *
 * Klepnutí do mapy energie, posun v rozpočtu pozornosti i odpověď na otázku
 * na dva končily v `state` — tedy v prohlížeči toho, kdo klikl. U mapy energie
 * je to obzvlášť naprázdno: celá je o tom najít okno, kdy mají sílu **oba**,
 * a druhý se k ní nedostal.
 */
class KlidVeStavu
{
    /**
     * Klíče, které patří databázi. Do stavu se neukládají.
     *
     * Odpověď na otázku na dva (`klAskMine`, `klAskDone`, `klAskQ`) taky ne:
     * ve společném stavu se druhému objevila rozepsaná věta v jeho políčku
     * a „odesláno" mu odemklo cizí odpověď dřív, než napsal svou. Obrazovka
     * ji posílá jednou zprávou při odeslání a stav bere ze serveru (`KL_ASK_NOW`).
     */
    public const SERVEROVE = ['klEn', 'klAttn', 'klAskLog', 'klTasks', 'klAskMine', 'klAskDone', 'klAskQ'];

    public function __construct(private readonly Klid $obsah) {}

    public function tykaSe(array $patch): bool
    {
        foreach (['klEn', 'klAttn', 'klAskMine', 'klAskDone', 'klTasks'] as $klic) {
            if (array_key_exists($klic, $patch)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function bezKlidu(array $patch): array
    {
        return array_diff_key($patch, array_flip(self::SERVEROVE));
    }

    /**
     * @param  array<string, mixed>  $stav  co je ve stavu uložené z dřívějška
     * @return array<string, mixed>
     */
    public function zpracuj(array $patch, GallerySpace $prostor, ?User $uzivatel, array $stav = []): array
    {
        if ($uzivatel === null) {
            return [];
        }

        $jmena = $this->jmena($prostor);

        // Jen skutečný seznam: vynulovaná místní kopie (`null`) nic nemaže.
        if (is_array($patch['klEn'] ?? null)) {
            $this->energie($patch['klEn'], $prostor, $jmena, $uzivatel);
        }

        if (is_array($patch['klAttn'] ?? null)) {
            $this->pozornost($patch['klAttn'], $prostor);
        }

        if (is_array($patch['klTasks'] ?? null)) {
            $this->cekaNaOkno($patch['klTasks'], $prostor, OdebraneVStavu::pro($patch, 'klTasks'), OdebraneVStavu::zmenene($patch, 'klTasks'));
        }

        // Napsaná odpověď dorazila dřív než potvrzení, takže tenhle patch už
        // ji nenese — dohledá se v tom, co ve stavu leží.
        $this->odpoved($patch + $stav, $prostor, $uzivatel);

        $obsah = $this->obsah->kolekce($prostor);

        return array_filter([
            'klEn' => $obsah['KL_EN'] ?? [],
            'klAttn' => $this->prani($obsah['KL_ATTN'] ?? []),
            'klAskLog' => $obsah['KL_ASK_LOG'] ?? [],
            'klTasks' => $obsah['KL_TASKS'] ?? [],
        ], fn ($v) => $v !== []);
    }

    /**
     * Co čeká na společné okno: `[{ id, name, need, route, tab, label }]`.
     *
     * „Probrat, co nás poslední měsíc štve" je věta, kterou si člověk nese
     * týdny — a mapa energie je jediné místo, kde se dá říct, kolik oken na ni
     * tenhle týden vůbec je. Tabulka se přitom jen četla, takže se do ní nedalo
     * nic napsat a seznam zůstával cizí.
     *
     * `route`, `tab` a `label` posílá obrazovka zpátky tak, jak je dostala —
     * jsou to odkazy na jiné obrazovky, ne text od člověka.
     *
     * @param  list<mixed>  $ukoly
     */
    private function cekaNaOkno(array $ukoly, GallerySpace $prostor, ?array $odebrane = null, ?array $zmenene = null): void
    {
        if (! Tabulky::je('wellbeing_tasks')) {
            return;
        }

        $znamé = DB::table('wellbeing_tasks')
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('done_at')
            ->pluck('id');

        $zustavaji = [];

        foreach (array_values($ukoly) as $poradi => $u) {
            $u = (array) $u;
            $nazev = trim((string) ($u['name'] ?? ''));

            if ($nazev === '') {
                continue;
            }

            $radek = [
                'name' => mb_substr($nazev, 0, 180),
                'needs_people' => max(0, min(2, (int) ($u['need'] ?? 1))),
                'route' => Vejde::neboNic($u['route'] ?? null, 40),
                'tab' => Vejde::neboNic($u['tab'] ?? null, 40),
                'label' => Vejde::neboNic($u['label'] ?? null),
                'sort_order' => $poradi,
                'updated_at' => now(),
            ];

            $id = (int) ($u['id'] ?? 0);

            if ($id > 0 && $znamé->contains($id)) {
                if (OdebraneVStavu::zmeneno($zmenene, $id)) {
                    DB::table('wellbeing_tasks')->where('id', $id)->update($radek);
                }
                $zustavaji[] = $id;

                continue;
            }

            /*
             * Číslo vydal server a mezi čekajícími není: věc je hotová (nebo
             * patří jinému páru). Starší opis seznamu ji dřív založil znovu
             * jako novou — odškrtnuté se vrátilo. Nová věc z obrazovky má `0`.
             */
            if ($id > 0) {
                continue;
            }

            $zustavaji[] = DB::table('wellbeing_tasks')->insertGetId($radek + [
                'gallery_space_id' => $prostor->id,
                'created_at' => now(),
            ]);
        }

        /*
         * Co ze seznamu zmizelo, je hotové — ne smazané.
         *
         * „Zavolat na úřad" se z čekání dostane jedinou cestou: udělá se.
         * Odstranit řádek by znamenalo, že po tom nezbude stopa, a příště
         * se to samé zapíše znovu jako nová věc.
         */
        // Prázdný seznam bez `__odebrane` by odškrtal všechno, co ještě čeká.
        if (! OdebraneVStavu::smiMazat($odebrane, $zustavaji)) {
            return;
        }

        DB::table('wellbeing_tasks')
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('done_at')
            ->when($zustavaji !== [], fn ($q) => $q->whereNotIn('id', $zustavaji))
            // Hotové je jen to, co prohlížeč sám odebral (viz OdebraneVStavu).
            ->when($odebrane !== null, fn ($q) => $q->whereIn('id', array_map('intval', $odebrane) ?: [0]))
            ->update(['done_at' => CarbonImmutable::now(), 'updated_at' => now()]);
    }

    /**
     * Mapa energie: `{ jméno: ['221', …×7] }`.
     *
     * Zapisuje se po buňkách, ne celá mřížka najednou — dvě sedmičky řetězců
     * se dají srovnat s tím, co v databázi je, a sáhne se jen na to, co se
     * opravdu změnilo.
     *
     * @param  array<string, mixed>  $mapa
     * @param  array<string, int>  $jmena
     */
    private function energie(array $mapa, GallerySpace $prostor, array $jmena, User $kdoPise): void
    {
        if (! Tabulky::je('wellbeing_energy')) {
            return;
        }

        foreach ($mapa as $jmeno => $dny) {
            $kdo = $jmena[(string) $jmeno] ?? null;

            if ($kdo === null) {
                continue;
            }

            /*
             * Jen svůj řádek.
             *
             * Počítač posílá mřížku obou lidí naráz, takže jedno kliknutí
             * přepsalo i všech 42 buněk toho druhého — a protože je `klEn`
             * mezi serverovými klíči, ze stavu se to vzít zpátky nedá. Stačilo
             * mít otevřenou starší záložku. Mapa přitom existuje právě proto,
             * aby se našlo okno, kdy mají sílu **oba**.
             *
             * Táž pojistka je ve `FilmyVeStavu` u známek a ve `VztahVeStavu`
             * u sporu; tady chyběla.
             */
            if ($kdo !== $kdoPise->id) {
                continue;
            }

            foreach ((array) $dny as $den => $urovne) {
                $den = (int) $den;
                $urovne = (string) $urovne;

                if ($den < 0 || $den > 6 || strlen($urovne) !== 3) {
                    continue;
                }

                for ($cast = 0; $cast < 3; $cast++) {
                    $uroven = (int) $urovne[$cast];

                    if ($uroven < 0 || $uroven > 2) {
                        continue;
                    }

                    DB::table('wellbeing_energy')->updateOrInsert(
                        [
                            'gallery_space_id' => $prostor->id, 'user_id' => $kdo,
                            'weekday' => $den, 'slot' => $cast,
                        ],
                        ['level' => $uroven, 'updated_at' => now(), 'created_at' => now()],
                    );
                }
            }
        }
    }

    /**
     * Rozpočet pozornosti: pole samotných přání, v pořadí, ve kterém se poslal.
     *
     * Prototyp posílá jen `want` — jména a měřítka zůstávají, jak byla. Když
     * si je dvojice ještě nezaložila, založí se teď z nabídky, kterou měla
     * na obrazovce před sebou.
     *
     * @param  list<mixed>  $prani
     */
    private function pozornost(array $prani, GallerySpace $prostor): void
    {
        if (! Tabulky::je('wellbeing_attention')) {
            return;
        }

        $radky = DB::table('wellbeing_attention')
            ->where('gallery_space_id', $prostor->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($radky->isEmpty()) {
            $this->zaloz($prostor);

            $radky = DB::table('wellbeing_attention')
                ->where('gallery_space_id', $prostor->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
        }

        foreach ($radky->values() as $poradi => $radek) {
            if (! array_key_exists($poradi, $prani)) {
                continue;
            }

            DB::table('wellbeing_attention')
                ->where('id', $radek->id)
                ->update([
                    'want' => max(0, min(60, (int) $prani[$poradi])),
                    'updated_at' => now(),
                ]);
        }
    }

    /** Nabídka z obrazovky jako první vlastní nastavení dvojice. */
    private function zaloz(GallerySpace $prostor): void
    {
        foreach ($this->obsah->kolekce($prostor)['KL_ATTN'] ?? [] as $poradi => $r) {
            DB::table('wellbeing_attention')->insert([
                'gallery_space_id' => $prostor->id,
                'key' => $r['key'],
                'name' => $r['name'],
                'want' => (int) $r['want'],
                'measure' => 'none',
                'note' => Vejde::neboNic($r['note']),
                'route' => $r['route'],
                'tab' => $r['tab'],
                'sort_order' => $poradi,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Odpověď na otázku na dva.
     *
     * Ukládá se, až když je odeslaná (`klAskDone`) — rozepsaná věta v poli
     * není odpověď a druhý by ji viděl dřív, než ji odesílatel dopsal.
     */
    private function odpoved(array $patch, GallerySpace $prostor, User $uzivatel): void
    {
        if (! Tabulky::je('wellbeing_answers') || ($patch['klAskDone'] ?? false) !== true) {
            return;
        }

        $text = trim((string) ($patch['klAskMine'] ?? ''));
        $otazka = trim((string) ($patch['klAskQ'] ?? ''));

        if ($text === '' || $otazka === '') {
            return;
        }

        DB::table('wellbeing_answers')->updateOrInsert(
            [
                'gallery_space_id' => $prostor->id,
                'user_id' => $uzivatel->id,
                'question' => mb_substr($otazka, 0, 500),
                'asked_on' => Cas::dnes()->toDateString(),
            ],
            ['answer' => $text, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /**
     * Zpátky do stavu jde jen to, co prototyp posílá — pole přání.
     *
     * @param  list<array<string, mixed>>  $pozornost
     * @return list<int>
     */
    private function prani(array $pozornost): array
    {
        return array_map(fn (array $r) => (int) $r['want'], $pozornost);
    }

    /**
     * Jméno → člověk, přesně v té podobě, ve které jméno poslala obrazovka.
     *
     * Bere se ze stejného zdroje jako to, co se kreslí — dva členové se
     * shodným jménem tam mají rozlišovací číslo a bez něj by se zápis trefil
     * do toho druhého.
     *
     * @return array<string, int>
     */
    private function jmena(GallerySpace $prostor): array
    {
        return array_flip($this->obsah->jmena($prostor));
    }
}
