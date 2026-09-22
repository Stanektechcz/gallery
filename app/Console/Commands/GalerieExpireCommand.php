<?php

namespace App\Console\Commands;

use App\Models\CoupleState;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Vypršovací domluvy: pravidlo, které nikdo neobnovil, se samo smaže.
 *
 * Tohle je jediná funkce, která **musí** běžet na serveru. Klient si odpočet
 * počítá z kalendáře při každém otevření, takže se vypršení pozná i bez serveru —
 * ale zapsané by nebylo, dokud si někdo aplikaci neotevře. Kdyby ji oba nechali
 * dva měsíce zavřenou, vrátili by se k domluvě, která podle obrazovky „právě
 * teď" vypršela, místo aby vypršela tehdy, kdy měla.
 *
 * **Bez upozornění, schválně.** Domluva zmizí a nikdo ji neporušil — to je celý
 * smysl téhle funkce a upozornění by ho zrušilo.
 *
 * Scaffold prototypu tu snižoval čítač `expDays`. Takový klíč ale v prototypu
 * neexistuje: klient drží `expExtra` (domluvy dvojice), `expRen` (počet
 * obnovení), `expEt` (trvalost) a `expDead` (co už vypršelo), a zbývající dny
 * počítá z data vzniku. Příkaz proto počítá stejně jako `expVals()`
 * v `galerie-mechanismy-logika.js` — a jen nad domluvami té které dvojice.
 */
class GalerieExpireCommand extends Command
{
    protected $signature = 'galerie:expire';

    protected $description = 'Zapíše domluvy, kterým vypršela platnost a nikdo je neobnovil';

    /** Domluva platí rok; každé obnovení přidá další. */
    private const ROK = 365;

    public function handle(): int
    {
        $dnes = CarbonImmutable::today();
        $vyprselo = 0;

        CoupleState::query()->each(function (CoupleState $stav) use ($dnes, &$vyprselo) {
            $data = $stav->data ?? [];
            /*
             * Domluvy dvojice, ne katalog z ukázky.
             *
             * Příkaz četl `EXPIRE` z `mechanismy.json` — tedy pravidla ukázkové
             * dvojice. Skutečná dvojice je nikdy nevidí (endpoint posílá sbírky
             * prázdné), ale do jejího stavu se zapisovalo `expDead` pro cizí id
             * `e1`…`e6` a příkaz hlásil „Vypršelo N domluv", které neexistují.
             * Vlastní domluvy drží `expExtra` (zakládá je Začátek hádky).
             */
            $pravidla = array_values(array_filter((array) ($data['expExtra'] ?? []), 'is_array'));

            if ($pravidla === []) {
                return;
            }

            $obnoveni = $data['expRen'] ?? [];
            $trvale = $data['expEt'] ?? [];
            $mrtve = $data['expDead'] ?? [];
            $zmeneno = false;

            foreach ($pravidla as $pravidlo) {
                $id = $pravidlo['id'] ?? null;

                if ($id === null || ! empty($mrtve[$id])) {
                    continue;
                }

                $jeTrvale = array_key_exists($id, $trvale) ? (bool) $trvale[$id] : (bool) ($pravidlo['eternal'] ?? false);

                if ($jeTrvale) {
                    continue;
                }

                if ($this->zbyva($pravidlo, (int) ($obnoveni[$id] ?? 0), $dnes) > 0) {
                    continue;
                }

                $mrtve[$id] = true;
                $zmeneno = true;
                $vyprselo++;
            }

            // Zapisuje se jen skutečná změna. Patch pro nic by zvedl `rev` a
            // otevřené aplikaci by při dalším uložení vrátil konflikt 409.
            if ($zmeneno) {
                $stav->applyPatch(['expDead' => $mrtve]);
            }
        });

        $this->info($vyprselo
            ? 'Vypršelo '.$vyprselo.' domluv. Bez upozornění — nikdo je neporušil.'
            : 'Nic nevypršelo.');

        return self::SUCCESS;
    }

    /** Kolik dní domluvě zbývá. Stejný výpočet jako `expVals()` na klientovi. */
    private function zbyva(array $pravidlo, int $obnoveni, CarbonImmutable $dnes): int
    {
        $lhuta = self::ROK * (1 + $obnoveni);
        $vznik = $this->datum($pravidlo['made'] ?? null);

        // Bez data vzniku zbývá to, co je v datech, plus rok za každé obnovení.
        if ($vznik === null) {
            return (int) ($pravidlo['days'] ?? 0) + $obnoveni * self::ROK;
        }

        return $lhuta - $vznik->diffInDays($dnes);
    }

    /** Datum v prototypu je český tvar „2. 3. 2026". */
    private function datum(?string $zapis): ?CarbonImmutable
    {
        if ($zapis === null) {
            return null;
        }

        $casti = array_map('trim', explode('.', $zapis));

        if (count($casti) < 3 || ! ctype_digit($casti[0]) || ! ctype_digit($casti[1]) || ! ctype_digit($casti[2])) {
            return null;
        }

        return CarbonImmutable::createFromDate((int) $casti[2], (int) $casti[1], (int) $casti[0])->startOfDay();
    }
}
