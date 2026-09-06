<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Obsah\Formulare;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Přepínače nastavení, které přišly jako změna stavu.
 *
 * Prototyp si jejich polohu drží v `state.sw` pod klíčem složeným z pořadí
 * (`revolut0-1`), takže „sync každé čtyři hodiny" nebo „upozornit na duplicitní
 * transakci" změnilo jen barvu v prohlížeči toho, kdo klikl. Napojení se dál
 * synchronizovalo (nebo ne) podle toho, co bylo v databázi.
 *
 * Co za kterým přepínačem stojí, ví `Formulare` — jediný popis pro obrazovku
 * i pro zápis. Dvě místa by se rozešla a přepínač by pak přepínal něco jiného,
 * než na čem stojí.
 */
class NastaveniVeStavu
{
    /** Klíče, které patří databázi. Do stavu se neukládají. */
    public const SERVEROVE = ['sw'];

    public function __construct(private readonly Formulare $formulare) {}

    public function tykaSe(array $patch): bool
    {
        return array_key_exists('sw', $patch);
    }

    /** @return array<string, mixed> */
    public function bezNastaveni(array $patch): array
    {
        return array_diff_key($patch, array_flip(self::SERVEROVE));
    }

    /**
     * Přehodí, co dvojice přehodila, a vrátí polohu všech přepínačů.
     *
     * @return array<string, mixed>
     */
    public function zpracuj(array $patch, GallerySpace $prostor, ?User $uzivatel): array
    {
        $sekce = [];

        foreach ($this->formulare->klice() as $klic) {
            $sekce[$klic] = $this->formulare->sekce($klic, $prostor, $uzivatel);
        }

        foreach ((array) ($patch['sw'] ?? []) as $klic => $zapnuto) {
            $cil = $this->cil((string) $klic, $sekce);

            if ($cil !== null) {
                $this->prehod($cil, (bool) $zapnuto, $prostor, $uzivatel);
            }
        }

        // Znovu, už po zápisu: odpověď nese skutečnou polohu, ne tu z kliknutí.
        $poloha = [];

        foreach ($this->formulare->klice() as $klic) {
            foreach ($this->formulare->sekce($klic, $prostor, $uzivatel) as $si => $s) {
                foreach ($s['rows'] as $ri => $r) {
                    $poloha[$klic.$si.'-'.$ri] = (bool) $r['on'];
                }
            }
        }

        return $poloha ? ['sw' => $poloha] : [];
    }

    /**
     * Klíč `revolut0-1` na řádek, ke kterému patří.
     *
     * @param  array<string, list<array<string, mixed>>>  $sekce
     * @return array<string, mixed>|null
     */
    private function cil(string $klic, array $sekce): ?array
    {
        if (! preg_match('/^([a-zA-Z]+)(\d+)-(\d+)$/', $klic, $shoda)) {
            return null;
        }

        [, $obrazovka, $si, $ri] = $shoda;

        return $sekce[$obrazovka][(int) $si]['rows'][(int) $ri]['cil'] ?? null;
    }

    /** @param  array<string, mixed>  $cil */
    private function prehod(array $cil, bool $zapnuto, GallerySpace $prostor, ?User $uzivatel): void
    {
        match ($cil['co'] ?? '') {
            'banka' => $this->banka((int) $cil['id'], $zapnuto, $prostor),
            'finance' => $this->finance((int) $cil['id'], (string) $cil['sloupec'], $zapnuto, $prostor),
            'predvolba' => $this->predvolba((string) $cil['klic'], $zapnuto, $uzivatel),
            'dedictvi' => $this->dedictvi((int) $cil['id'], $zapnuto, $uzivatel),
            // `nemenne` je přístup do trezoru: odebrat ho druhému z dvojice
            // jedním přepnutím bez potvrzení patří do Administrace, ne sem.
            default => null,
        };
    }

    private function banka(int $id, bool $zapnuto, GallerySpace $prostor): void
    {
        DB::table('bank_connections')
            ->where('id', $id)
            ->where('gallery_space_id', $prostor->id)
            ->update(['sync_enabled' => $zapnuto, 'updated_at' => now()]);
    }

    private function finance(int $id, string $sloupec, bool $zapnuto, GallerySpace $prostor): void
    {
        // Jen sloupce, které formuláře opravdu nabízejí — klíč ze stavu je
        // text od klienta a do `update()` mu nic jiného projít nesmí.
        if (! in_array($sloupec, ['warn_duplicates', 'warn_unusual_amount', 'warn_low_balance'], true)) {
            return;
        }

        DB::table('finance_settings')
            ->where('id', $id)
            ->where('gallery_space_id', $prostor->id)
            ->update([$sloupec => $zapnuto, 'updated_at' => now()]);
    }

    private function predvolba(string $klic, bool $zapnuto, ?User $uzivatel): void
    {
        if ($uzivatel === null || ! Schema::hasTable('user_settings')) {
            return;
        }

        DB::table('user_settings')->updateOrInsert(
            ['user_id' => $uzivatel->id, 'key' => $klic],
            ['value' => $zapnuto ? '1' : '0', 'updated_at' => now()],
        );
    }

    private function dedictvi(int $id, bool $zapnuto, ?User $uzivatel): void
    {
        if ($uzivatel === null) {
            return;
        }

        DB::table('legacy_plans')
            ->where('id', $id)
            ->where('user_id', $uzivatel->id)
            ->update(['status' => $zapnuto ? 'active' : 'disabled', 'updated_at' => now()]);
    }
}
