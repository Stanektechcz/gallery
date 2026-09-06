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

        if (array_key_exists('emItems', $patch)) {
            $this->nouze((array) $patch['emItems'], $prostor, $uzivatel);
        }

        if (array_key_exists('paper', $patch)) {
            $this->papir((array) $patch['paper'], $prostor);
        }

        $obsah = $this->obsah->kolekce($prostor);

        return array_filter([
            'storyList' => $obsah['STORY'] ?? [],
            'msList' => $obsah['STORYMS'] ?? [],
            'emItems' => $obsah['EM_ITEMS'] ?? [],
            'paper' => $obsah['PAPER_ROWS'] ?? [],
        ], fn ($v) => $v !== []);
    }

    /**
     * Kapitoly: `[id, název, rok, stav, fotek, zápisů, text]`.
     *
     * Fotky a zápisy se nepřebírají — ty aplikace počítá sama a číslo
     * z prohlížeče by bylo jen jeho stará kopie.
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
            $nazev = trim((string) ($k[1] ?? ''));

            if ($nazev === '') {
                continue;
            }

            $radek = [
                'title' => $nazev,
                'year' => (string) ($k[2] ?? '') ?: null,
                'status' => $this->stav((string) ($k[3] ?? '')),
                'body' => (string) ($k[6] ?? ''),
                'sort_order' => $poradi,
                'updated_at' => now(),
            ];

            $uuid = (string) ($k[0] ?? '');

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
