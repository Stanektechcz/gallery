<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Support\Tabulky;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Zařazení transakce do kategorie z obrazovky Transakce.
 *
 * Výběr kategorie („Albert → Potraviny", i hromadně) končil v `txCat` ve
 * stavu prohlížeče. Obrazovka Transakce pak ukazovala novou kategorii, ale
 * rozpočet, přehled a upozornění se počítají na serveru z knihy — a tam
 * transakce zůstala nezařazená. Dva různé výsledky o téže platbě.
 *
 * Změna se proto propíše do `transactions.category_id`. Klíč ve stavu
 * zůstává, protože drží tlačítko „Zpět": když ze stavu transakce zmizí
 * (vrácení první změny), obnoví se kategorie, kterou měla před prvním
 * zásahem — ta se pamatuje v `txCatPuvodni`.
 */
class FinanceVeStavu
{
    public function tykaSe(array $patch): bool
    {
        return is_array($patch['txCat'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $patch
     * @param  array<string, mixed>  $predtim  stav před zápisem
     * @return array<string, mixed> patch doplněný o `txCatPuvodni`
     */
    public function zpracuj(array $patch, array $predtim, GallerySpace $prostor): array
    {
        if (! Tabulky::je('transactions') || ! Tabulky::je('finance_categories')) {
            return $patch;
        }

        try {
            $ted = $this->mapa($patch['txCat']);
            $pred = $this->mapa($predtim['txCat'] ?? []);
            $puvodni = is_array($predtim['txCatPuvodni'] ?? null) ? $predtim['txCatPuvodni'] : [];

            $zmenene = array_filter($ted, fn (string $nazev, string $uuid) => ($pred[$uuid] ?? null) !== $nazev, ARRAY_FILTER_USE_BOTH);
            $vracene = array_diff_key($pred, $ted);

            if ($zmenene === [] && $vracene === []) {
                return $patch;
            }

            $kategorie = DB::table('finance_categories')
                ->where('gallery_space_id', $prostor->id)
                ->pluck('id', 'name')
                ->mapWithKeys(fn ($id, $nazev) => [Str::lower($nazev) => (int) $id]);

            $transakce = Transaction::with('category')
                ->where('gallery_space_id', $prostor->id)
                ->whereIn('uuid', array_keys($zmenene + $vracene))
                ->get()
                ->keyBy('uuid');

            foreach ($zmenene as $uuid => $nazev) {
                $t = $transakce[$uuid] ?? null;
                $id = $this->idKategorie($nazev, $kategorie);

                // Neznámá kategorie (přejmenovaná mezitím jinde) se nezapisuje.
                if ($t === null || $id === false) {
                    continue;
                }

                if (! array_key_exists($uuid, $puvodni)) {
                    $puvodni[$uuid] = $t->category?->name ?? 'Nezařazeno';
                }

                if ((int) $t->category_id !== (int) $id || ($id === null && $t->category_id !== null)) {
                    $t->update(['category_id' => $id]);
                }
            }

            foreach (array_keys($vracene) as $uuid) {
                $t = $transakce[$uuid] ?? null;

                if ($t === null || ! array_key_exists($uuid, $puvodni)) {
                    continue;
                }

                $id = $this->idKategorie((string) $puvodni[$uuid], $kategorie);

                if ($id !== false) {
                    $t->update(['category_id' => $id]);
                }

                unset($puvodni[$uuid]);
            }

            $patch['txCatPuvodni'] = (object) $puvodni;
        } catch (\Throwable $e) {
            // Zařazení transakce nesmí shodit zápis všeho ostatního ve stavu.
            Log::warning('Zařazení transakcí ze stavu se nepodařilo propsat', ['chyba' => $e->getMessage()]);
        }

        return $patch;
    }

    /** @return array<string, string> `{uuid transakce: název kategorie}` */
    private function mapa(mixed $hodnota): array
    {
        $mapa = [];

        foreach ((array) $hodnota as $uuid => $nazev) {
            if (is_string($uuid) && Str::isUuid($uuid) && is_string($nazev) && $nazev !== '') {
                $mapa[$uuid] = $nazev;
            }
        }

        return $mapa;
    }

    /**
     * @param  Collection<string, int>  $kategorie
     * @return int|null|false id, `null` pro nezařazené, `false` pro neznámou
     */
    private function idKategorie(string $nazev, $kategorie): int|null|false
    {
        if (Str::lower($nazev) === 'nezařazeno') {
            return null;
        }

        return $kategorie[Str::lower($nazev)] ?? false;
    }
}
