<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Obsah\Sdileni;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Zapečetěné vzkazy, které přišly jako změna stavu.
 *
 * „Zapečetěno do časové kapsle · otevře se 4. září 2027," řekl prototyp —
 * a uložil to do `state.kapsules`, odkud se to při vymazání prohlížeče ztratí.
 * Dopis, který má přijít za rok, je přesně ta věc, u které na tom záleží
 * nejvíc: mezitím se vymění telefon, a s ním i celá lokální kopie.
 *
 * Zapisují se **jen nové kapsle**. Zapečetěné se nemění a neruší — o to jde,
 * a `kapsList()` navíc vrací i ty, jejichž text server ještě neposílá,
 * takže „co v seznamu není, dvojice smazala" by tady mazalo obsah.
 */
class KapsleVeStavu
{
    /** Klíče, které patří databázi. Do stavu se neukládají. */
    public const SERVEROVE = ['kapsules'];

    public function __construct(private readonly Sdileni $obsah) {}

    public function tykaSe(array $patch): bool
    {
        return array_key_exists('kapsules', $patch);
    }

    /** @return array<string, mixed> */
    public function bezKapsli(array $patch): array
    {
        return array_diff_key($patch, array_flip(self::SERVEROVE));
    }

    /** @return array<string, mixed> */
    public function zpracuj(array $patch, GallerySpace $prostor, ?User $uzivatel): array
    {
        if ($uzivatel === null || ! Schema::hasTable('time_capsules')) {
            return [];
        }

        $jmena = $prostor->members()->pluck('users.id', 'users.name')->all();

        $znama = DB::table('time_capsules')
            ->where('gallery_space_id', $prostor->id)
            ->pluck('uuid')
            ->flip();

        foreach ((array) ($patch['kapsules'] ?? []) as $k) {
            $k = (array) $k;
            $id = (string) ($k['id'] ?? '');

            // Kapsle ze serveru má uuid; nově zapečetěná má „z…" nebo „k…".
            if ($id === '' || isset($znama[$id])) {
                continue;
            }

            $this->zapecet($k, $prostor, $uzivatel, $jmena);
        }

        $kapsle = $this->obsah->kolekce($prostor)['KAPS'] ?? [];

        return $kapsle ? ['kapsules' => $kapsle] : [];
    }

    /**
     * @param  array<string, mixed>  $k
     * @param  array<string, int>  $jmena
     */
    private function zapecet(array $k, GallerySpace $prostor, User $uzivatel, array $jmena): void
    {
        $nadpis = trim((string) ($k['title'] ?? ''));

        if ($nadpis === '') {
            return;
        }

        $kdy = $this->termin($k);

        DB::table('time_capsules')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $prostor->id,
            'created_by' => $jmena[(string) ($k['from'] ?? '')] ?? $uzivatel->id,
            'title' => $nadpis,
            'message' => (string) ($k['body'] ?? ''),
            'deliver_at' => $kdy,
            'status' => 'sealed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Kdy se otevře.
     *
     * Kapsle vázaná na událost („až se přestěhujeme") datum nemá; aplikace pro
     * ni sloupec má (`event_id`), ale prototyp posílá jen klíč spouštěče, ke
     * kterému žádná událost nepatří. Do té doby se počítá s rokem — a to je
     * pořád lepší než kapsle bez data, kterou nikdy nic neotevře.
     */
    private function termin(array $k): string
    {
        $datum = (string) ($k['open'] ?? '');

        if ($datum !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
            return $datum;
        }

        return CarbonImmutable::now()->addYear()->toDateString();
    }
}
