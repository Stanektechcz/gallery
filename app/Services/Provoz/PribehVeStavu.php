<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Obsah\Pribeh;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Příběh, nouzový přístup a papírová záloha, které přišly jako změna stavu.
 *
 * Tři obrazovky, kde stav rozhodoval o věcech, které mají přežít prohlížeč:
 * kapitoly příběhu (`storyList`), co se druhému otevře v nouzi (`emItems`)
 * a list, který má viset v šuplíku, když nic nefunguje (`paper`).
 *
 * U nouzového přístupu je to nejcitlivější: „soukromé zápisy v deníku se
 * nouzově neodemknou" je rozhodnutí, které musí platit i na druhém zařízení,
 * ne jen v prohlížeči toho, kdo přepínač vypnul.
 */
class PribehVeStavu
{
    /** Klíče, které patří databázi. Do stavu se neukládají. */
    public const SERVEROVE = ['storyList', 'msList', 'emItems', 'paper'];

    /** Měsíce ve druhém pádu — tak, jak je píše osa milníků. */
    private const MESICE = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června',
        'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

    public function __construct(private readonly Pribeh $obsah) {}

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
    public function bezPribehu(array $patch): array
    {
        return array_diff_key($patch, array_flip(self::SERVEROVE));
    }

    /** @return array<string, mixed> */
    public function zpracuj(array $patch, GallerySpace $prostor, ?User $uzivatel): array
    {
        if (array_key_exists('storyList', $patch)) {
            $this->kapitoly((array) $patch['storyList'], $prostor, $uzivatel);
        }

        // Milníky až po kapitolách: nový milník se často zakládá do kapitoly,
        // která v témže patchi teprve vzniká.
        if (array_key_exists('msList', $patch)) {
            $this->milniky((array) $patch['msList'], $prostor);
        }

        if (array_key_exists('emItems', $patch)) {
            $this->nouze((array) $patch['emItems'], $prostor, $uzivatel);
        }

        if (array_key_exists('paper', $patch)) {
            $this->papir((array) $patch['paper'], $prostor);
        }

        $obsah = $this->obsah->kolekce($prostor);

        return array_filter([
            'storyList' => $this->kapitolyProKlienta($obsah['STORY'] ?? []),
            'msList' => $this->milnikyProKlienta($obsah['STORYMS'] ?? []),
            'emItems' => $obsah['EM_ITEMS'] ?? [],
            'paper' => $obsah['PAPER_ROWS'] ?? [],
        ], fn ($v) => $v !== []);
    }

    /**
     * Kapitoly: `{ id, title, year, status, photoN, n, text }`.
     *
     * Fotky a zápisy se nepřebírají — ty aplikace počítá sama a číslo
     * z prohlížeče by bylo jen jeho stará kopie.
     *
     * Prototyp posílá objekty, `STORY` v `galerie-data.js` je pole. Čte se
     * obojí: klíč, když je, jinak pozice. Dokud se četla jen pozice, přišla
     * z obrazovky kapitola bez názvu — a řádek bez názvu se přeskakuje, takže
     * po první úpravě zmizely z tabulky **všechny** kapitoly.
     *
     * @param  list<mixed>  $kapitoly
     */
    private function kapitoly(array $kapitoly, GallerySpace $prostor, ?User $uzivatel): void
    {
        if (! Schema::hasTable('couple_story_chapters')) {
            return;
        }

        $znamé = DB::table('couple_story_chapters')
            ->where('gallery_space_id', $prostor->id)
            ->pluck('id', 'uuid');

        $zustavaji = [];

        foreach (array_values($kapitoly) as $poradi => $k) {
            $k = (array) $k;
            $nazev = trim((string) ($k['title'] ?? $k[1] ?? ''));

            if ($nazev === '') {
                continue;
            }

            $radek = [
                'title' => $nazev,
                'year' => (string) ($k['year'] ?? $k[2] ?? '') ?: null,
                'status' => $this->stav((string) ($k['status'] ?? $k[3] ?? '')),
                'body' => (string) ($k['text'] ?? $k[6] ?? ''),
                'sort_order' => $poradi,
                'updated_at' => now(),
            ];

            $uuid = (string) ($k['id'] ?? $k[0] ?? '');

            if (isset($znamé[$uuid])) {
                DB::table('couple_story_chapters')->where('id', $znamé[$uuid])->update($radek);
                $zustavaji[] = $znamé[$uuid];

                continue;
            }

            $zustavaji[] = DB::table('couple_story_chapters')->insertGetId($radek + [
                'uuid' => (string) Str::uuid(),
                'gallery_space_id' => $prostor->id,
                'created_by' => $uzivatel?->id,
                'created_at' => now(),
            ]);
        }

        // Co v seznamu není, dvojice smazala. Milníky zůstávají — přišly
        // o kapitolu, ne o to, že se staly.
        DB::table('couple_story_chapters')
            ->where('gallery_space_id', $prostor->id)
            ->when($zustavaji !== [], fn ($q) => $q->whereNotIn('id', $zustavaji))
            ->delete();
    }

    /**
     * Milníky na ose: `{ id, y, date, title, note, icon, ch, iso }`.
     *
     * Osa příběhu byla jediné místo, kde se dvojice ptá „co se kdy stalo" —
     * a jediné, které si odpověď nikam nezapsalo. „Milník přidán na osu"
     * platilo do zavření záložky.
     *
     * `iso` posílá prohlížeč vedle napsaného data, protože „4. dubna 2026" je
     * text a den v tabulce je datum. Když nedorazí (starší klient, ruční
     * požadavek), datum se z textu přečte — a když ani to nejde, řádek se
     * **nezaloží**: milník s vymyšleným datem by na ose stál na špatném místě.
     *
     * @param  list<mixed>  $milniky
     */
    private function milniky(array $milniky, GallerySpace $prostor): void
    {
        if (! Schema::hasTable('couple_story_milestones')) {
            return;
        }

        $kapitoly = DB::table('couple_story_chapters')
            ->where('gallery_space_id', $prostor->id)
            ->pluck('id', 'uuid');

        $znamé = DB::table('couple_story_milestones')
            ->where('gallery_space_id', $prostor->id)
            ->pluck('id', 'uuid');

        $zustavaji = [];

        foreach (array_values($milniky) as $m) {
            $m = (array) $m;
            $nazev = trim((string) ($m['title'] ?? $m[3] ?? ''));
            $kdy = $this->kdy($m);

            if ($nazev === '' || $kdy === null) {
                continue;
            }

            $kapitola = (string) ($m['ch'] ?? $m[6] ?? '');

            $radek = [
                'chapter_id' => $kapitoly[$kapitola] ?? null,
                'happened_on' => $kdy->format('Y-m-d'),
                'title' => $nazev,
                'note' => (string) ($m['note'] ?? $m[4] ?? ''),
                'icon' => (string) ($m['icon'] ?? $m[5] ?? '') ?: 'ph-sparkle',
                'updated_at' => now(),
            ];

            $uuid = (string) ($m['id'] ?? $m[0] ?? '');

            if (isset($znamé[$uuid])) {
                DB::table('couple_story_milestones')->where('id', $znamé[$uuid])->update($radek);
                $zustavaji[] = $znamé[$uuid];

                continue;
            }

            $zustavaji[] = DB::table('couple_story_milestones')->insertGetId($radek + [
                'uuid' => (string) Str::uuid(),
                'gallery_space_id' => $prostor->id,
                'created_at' => now(),
            ]);
        }

        DB::table('couple_story_milestones')
            ->where('gallery_space_id', $prostor->id)
            ->when($zustavaji !== [], fn ($q) => $q->whereNotIn('id', $zustavaji))
            ->delete();
    }

    /**
     * Kdy se to stalo. `iso` z prohlížeče, jinak napsaný text.
     *
     * @param  array<mixed>  $m
     */
    private function kdy(array $m): ?CarbonImmutable
    {
        $iso = trim((string) ($m['iso'] ?? $m[7] ?? ''));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso) === 1) {
            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $iso)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        return $this->zTextu(
            (string) ($m['date'] ?? $m[2] ?? ''),
            (string) ($m['y'] ?? $m[1] ?? ''),
        );
    }

    /**
     * „4. dubna 2026", „4. 4. 2026" nebo „2026-04-04" na datum.
     *
     * Rok z vedlejšího pole se použije, jen když ho v textu není — dvě různá
     * čísla by jinak tiše přepsala to, co člověk napsal.
     */
    private function zTextu(string $text, string $rok): ?CarbonImmutable
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $c) === 1) {
            [$r, $mesic, $den] = [(int) $c[1], (int) $c[2], (int) $c[3]];
        } elseif (preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})?$/u', $text, $c) === 1) {
            [$den, $mesic, $r] = [(int) $c[1], (int) $c[2], (int) ($c[3] ?? 0)];
        } elseif (preg_match('/^(\d{1,2})\.\s*(\p{L}+)\s*(\d{4})?$/u', $text, $c) === 1) {
            $mesic = array_search(mb_strtolower($c[2]), self::MESICE, true);

            if ($mesic === false) {
                return null;
            }

            [$den, $r] = [(int) $c[1], (int) ($c[3] ?? 0)];
        } else {
            return null;
        }

        $r = $r ?: (int) $rok;

        if ($r < 1900 || $r > 2200 || ! checkdate($mesic, $den, $r)) {
            return null;
        }

        return CarbonImmutable::create($r, $mesic, $den)->startOfDay();
    }

    /**
     * Kapitoly ze `STORY` do tvaru, ve kterém je prototyp drží ve stavu.
     *
     * Odpověď se u klienta stává `state.storyList` a obrazovka z ní kreslí
     * přímo. Kdyby dostala pole, `c.title` by bylo `undefined` a seznam
     * kapitol by po uložení zůstal prázdný.
     *
     * @param  list<mixed>  $kapitoly
     * @return list<array<string, mixed>>
     */
    private function kapitolyProKlienta(array $kapitoly): array
    {
        return array_map(fn (array $k) => [
            'id' => $k[0], 'title' => $k[1], 'year' => $k[2], 'status' => $k[3],
            'photoN' => $k[4], 'n' => $k[5], 'text' => $k[6],
        ], $kapitoly);
    }

    /**
     * A milníky ze `STORYMS` z téhož důvodu.
     *
     * @param  list<mixed>  $milniky
     * @return list<array<string, mixed>>
     */
    private function milnikyProKlienta(array $milniky): array
    {
        return array_map(fn (array $m) => [
            'id' => $m[0], 'y' => $m[1], 'date' => $m[2], 'title' => $m[3],
            'note' => $m[4], 'icon' => $m[5], 'ch' => $m[6], 'iso' => $m[7] ?? null,
        ], $milniky);
    }

    /**
     * Nouzový přístup: `[{ id, label, note, on }]`.
     *
     * Změna se zapisuje **i do protokolu**. „Makinka zapnula sdílení pojistek"
     * je věta, kterou musí druhý najít i za rok — jinak se nedá zpětně zjistit,
     * kdo co komu otevřel.
     *
     * @param  list<mixed>  $polozky
     */
    private function nouze(array $polozky, GallerySpace $prostor, ?User $uzivatel): void
    {
        if (! Schema::hasTable('emergency_access_items')) {
            return;
        }

        $stav = DB::table('emergency_access_items')
            ->where('gallery_space_id', $prostor->id)
            ->get()
            ->keyBy('uuid');

        $zustavaji = [];

        foreach (array_values($polozky) as $poradi => $p) {
            $p = (array) $p;
            $popis = trim((string) ($p['label'] ?? ''));

            if ($popis === '') {
                continue;
            }

            $sdilet = (bool) ($p['on'] ?? false);
            $uuid = (string) ($p['id'] ?? '');
            $puvodni = $stav[$uuid] ?? null;

            $radek = [
                'label' => $popis,
                'note' => (string) ($p['note'] ?? ''),
                'is_shared' => $sdilet,
                'sort_order' => $poradi,
                'updated_at' => now(),
            ];

            if ($puvodni !== null) {
                DB::table('emergency_access_items')->where('id', $puvodni->id)->update($radek);
                $zustavaji[] = $puvodni->id;

                if ((bool) $puvodni->is_shared !== $sdilet) {
                    $this->zapis($prostor, $uzivatel, ($uzivatel?->name ?? 'Někdo').' '
                        .($sdilet ? 'zapnul(a) sdílení' : 'vypnul(a) sdílení').' — '.$popis);
                }

                continue;
            }

            $zustavaji[] = DB::table('emergency_access_items')->insertGetId($radek + [
                'uuid' => (string) Str::uuid(),
                'gallery_space_id' => $prostor->id,
                'created_at' => now(),
            ]);
        }

        DB::table('emergency_access_items')
            ->where('gallery_space_id', $prostor->id)
            ->when($zustavaji !== [], fn ($q) => $q->whereNotIn('id', $zustavaji))
            ->delete();
    }

    /**
     * Papírová záloha: `[{ id, label, value, on, changed }]`.
     *
     * Většina těch řádků je text, který zná jen člověk — kde leží obálka se
     * záložním klíčem, číslo na právníka. Aplikace je proto jen ukládá.
     *
     * @param  list<mixed>  $radky
     */
    private function papir(array $radky, GallerySpace $prostor): void
    {
        if (! Schema::hasTable('paper_backup_rows')) {
            return;
        }

        $znamé = DB::table('paper_backup_rows')
            ->where('gallery_space_id', $prostor->id)
            ->pluck('id', 'uuid');

        $zustavaji = [];

        foreach (array_values($radky) as $poradi => $r) {
            $r = (array) $r;
            $popis = trim((string) ($r['label'] ?? ''));

            if ($popis === '') {
                continue;
            }

            $radek = [
                'label' => $popis,
                'value' => (string) ($r['value'] ?? ''),
                'is_done' => (bool) ($r['on'] ?? false),
                'changed' => (bool) ($r['changed'] ?? false),
                'sort_order' => $poradi,
                'updated_at' => now(),
            ];

            $uuid = (string) ($r['id'] ?? '');

            if (isset($znamé[$uuid])) {
                DB::table('paper_backup_rows')->where('id', $znamé[$uuid])->update($radek);
                $zustavaji[] = $znamé[$uuid];

                continue;
            }

            $zustavaji[] = DB::table('paper_backup_rows')->insertGetId($radek + [
                'uuid' => (string) Str::uuid(),
                'gallery_space_id' => $prostor->id,
                'created_at' => now(),
            ]);
        }

        DB::table('paper_backup_rows')
            ->where('gallery_space_id', $prostor->id)
            ->when($zustavaji !== [], fn ($q) => $q->whereNotIn('id', $zustavaji))
            ->delete();
    }

    private function zapis(GallerySpace $prostor, ?User $uzivatel, string $text): void
    {
        if (! Schema::hasTable('emergency_access_log')) {
            return;
        }

        DB::table('emergency_access_log')->insert([
            'gallery_space_id' => $prostor->id,
            'user_id' => $uzivatel?->id,
            'text' => mb_substr($text, 0, 500),
            'happened_at' => CarbonImmutable::now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function stav(string $stav): string
    {
        return match ($stav) {
            'hotovo' => 'done',
            'zveřejněno' => 'published',
            default => 'draft',
        };
    }
}
