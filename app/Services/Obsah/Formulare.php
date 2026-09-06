<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Přepínače nastavení — a co za každým z nich doopravdy stojí.
 *
 * Prototyp má formuláře napsané jako trojice `[popisek, poznámka, zapnuto]`
 * a stav přepínače si drží v `state.sw` pod klíčem složeným z **pořadí**
 * (`revolut0-1`). Zapnout „sync každé čtyři hodiny" tedy neudělalo nic než
 * změnu barvy v prohlížeči toho, kdo klikl.
 *
 * Tenhle popis je jediný zdroj pro obě strany: obrazovka z něj kreslí
 * `AFORMS`, zápis podle něj pozná, co který přepínač znamená. Kdyby to byla
 * dvě místa, rozešla by se — a přepínač by přepínal něco jiného, než na čem
 * stojí.
 *
 * Posílá se jen to, co má oporu. Řádek „Připojit k Chromecastu v obýváku ·
 * naposledy použito včera" tady není: aplikace o žádném Chromecastu neví.
 */
class Formulare
{
    /** Předvolby promítání a jejich výchozí stav, když si je nikdo nenastavil. */
    private const PROMITANI = [
        'televize' => [
            ['slideshow.large_captions', 'Zvětšené popisky', 'čitelné z gauče', true],
            ['slideshow.autostart', 'Automaticky spustit po připojení', '', false],
        ],
        'prubeh' => [
            ['slideshow.crossfade', 'Prolínání', 'místo tvrdého střihu', true],
            ['slideshow.shuffle', 'Náhodné pořadí', '', false],
            ['slideshow.skip_video', 'Přeskočit videa', '', false],
        ],
    ];

    /** Upozornění, která umí rozpočet — sloupec v `finance_settings`. */
    private const VAROVANI = [
        ['warn_duplicates', 'Upozornit na duplicitní transakci', 'když přijde dvakrát totéž'],
        ['warn_unusual_amount', 'Upozornit na neobvyklou částku', 'proti běžné útratě v kategorii'],
        ['warn_low_balance', 'Upozornit na nízký zůstatek', 'než dojdou peníze na účtu'],
    ];

    /**
     * Sekce a řádky pro jednu obrazovku.
     *
     * Každý řádek nese `cil` — co se má změnit, když se přepínač přehodí.
     *
     * @return list<array{label: string, rows: list<array<string, mixed>>}>
     */
    public function sekce(string $klic, GallerySpace $prostor, ?User $uzivatel): array
    {
        return match ($klic) {
            'revolut' => $this->penize($prostor),
            'tv' => $this->promitani($uzivatel),
            'vault' => $this->trezor($prostor, $uzivatel),
            default => [],
        };
    }

    /** Klíče obrazovek, které se posílají. */
    public function klice(): array
    {
        return ['revolut', 'tv', 'vault'];
    }

    // ——— peníze ———

    /** @return list<array{label: string, rows: list<array<string, mixed>>}> */
    private function penize(GallerySpace $prostor): array
    {
        $sekce = [];

        if (Schema::hasTable('bank_connections')) {
            $napojeni = DB::table('bank_connections')
                ->where('gallery_space_id', $prostor->id)
                ->whereNull('revoked_at')
                ->orderBy('id')
                ->get(['id', 'institution_name', 'provider', 'sync_enabled', 'last_synced_at']);

            if ($napojeni->isNotEmpty()) {
                $sekce[] = [
                    'label' => 'Napojení',
                    'rows' => $napojeni->map(fn (object $b) => [
                        'label' => (string) ($b->institution_name ?: $b->provider),
                        'note' => $b->last_synced_at
                            ? 'naposledy '.$this->kdy(CarbonImmutable::parse($b->last_synced_at))
                            : 'zatím se nesynchronizovalo',
                        'on' => (bool) $b->sync_enabled,
                        'cil' => ['co' => 'banka', 'id' => (int) $b->id],
                    ])->all(),
                ];
            }
        }

        $nastaveni = Schema::hasTable('finance_settings')
            ? DB::table('finance_settings')->where('gallery_space_id', $prostor->id)->first()
            : null;

        if ($nastaveni !== null) {
            $sekce[] = [
                'label' => 'Upozornění',
                'rows' => array_map(fn (array $v) => [
                    'label' => $v[1],
                    'note' => $v[2],
                    'on' => (bool) ($nastaveni->{$v[0]} ?? false),
                    'cil' => ['co' => 'finance', 'sloupec' => $v[0], 'id' => (int) $nastaveni->id],
                ], self::VAROVANI),
            ];
        }

        return $sekce;
    }

    // ——— promítání ———

    /** @return list<array{label: string, rows: list<array<string, mixed>>}> */
    private function promitani(?User $uzivatel): array
    {
        if ($uzivatel === null || ! Schema::hasTable('user_settings')) {
            return [];
        }

        $ulozene = DB::table('user_settings')
            ->where('user_id', $uzivatel->id)
            ->pluck('value', 'key');

        $sekce = [];

        foreach (['televize' => 'Televize', 'prubeh' => 'Průběh'] as $klic => $nazev) {
            $sekce[] = [
                'label' => $nazev,
                'rows' => array_map(fn (array $p) => [
                    'label' => $p[1],
                    'note' => $p[2],
                    // Předvolba, kterou si nikdo nenastavil, má výchozí hodnotu
                    // — ne nulu. Vypnuté prolínání by nikdo nezapínal omylem.
                    'on' => isset($ulozene[$p[0]]) ? $ulozene[$p[0]] === '1' : $p[3],
                    'cil' => ['co' => 'predvolba', 'klic' => $p[0]],
                ], self::PROMITANI[$klic]),
            ];
        }

        return $sekce;
    }

    // ——— trezor ———

    /** @return list<array{label: string, rows: list<array<string, mixed>>}> */
    private function trezor(GallerySpace $prostor, ?User $uzivatel): array
    {
        // Vlastník první — je to jeho archiv a v seznamu se to má poznat.
        $lide = $prostor->members()
            ->orderByRaw('users.id = ? desc', [$prostor->owner_id])
            ->orderBy('users.id')
            ->get(['users.id', 'users.name']);

        if ($lide->isEmpty()) {
            return [];
        }

        $sekce = [[
            'label' => 'Přístup',
            'rows' => $lide->map(fn (object $u) => [
                'label' => $u->name,
                'note' => (int) $u->id === (int) $prostor->owner_id ? 'vlastník archivu' : 'člen páru',
                'on' => true,
                /*
                 * Přístup se odsud nemění.
                 *
                 * Odebrat druhému z dvojice trezor jedním přepnutím bez
                 * potvrzení je něco jiného než zapnout prolínání — patří to
                 * do Administrace, kde se u toho ptáme.
                 */
                'cil' => ['co' => 'nemenne'],
            ])->all(),
        ]];

        if ($uzivatel !== null && Schema::hasTable('legacy_plans')) {
            $plan = DB::table('legacy_plans')->where('user_id', $uzivatel->id)->first();

            if ($plan !== null && $plan->contact_name) {
                $sekce[] = [
                    'label' => 'Dědictví',
                    'rows' => [[
                        'label' => 'Důvěrník '.$plan->contact_name,
                        'note' => 'po '.$this->mesicu((int) $plan->inactivity_months).' nečinnosti',
                        'on' => $plan->status !== 'disabled',
                        'cil' => ['co' => 'dedictvi', 'id' => (int) $plan->id],
                    ]],
                ];
            }
        }

        return $sekce;
    }

    // ——— formát ———

    private function mesicu(int $kolik): string
    {
        return $kolik.' '.match (true) {
            $kolik === 1 => 'měsíci',
            $kolik >= 2 && $kolik <= 4 => 'měsících',
            default => 'měsících',
        };
    }

    private function kdy(CarbonImmutable $kdy): string
    {
        return match (true) {
            $kdy->isToday() => 'dnes '.$kdy->format('G:i'),
            $kdy->isYesterday() => 'včera',
            default => $kdy->format('j. n.'),
        };
    }
}
