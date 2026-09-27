<?php

namespace App\Models\Concerns;

use App\Jobs\Media\ObnovHledaniJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

/**
 * Štítek, osoba, místo nebo album, jehož jméno je v `search_text` fotek.
 *
 * Po přejmenování zůstával v hledaném textu fotek starý název: „Lysá hora"
 * přejmenovaná na „Lysá hora (Beskydy)" se podle nového jména nenašla nikde,
 * podle starého pořád ano. Změní-li se sloupec, který se do textu skládá,
 * přepočítají se fotky, které na záznam ukazují (`ObnovHledaniJob`).
 *
 * Chyba při zařazení úlohy přejmenování nezastaví — hledaný text je odvozený
 * a `gallery:rebuild-search` ho kdykoli složí znovu.
 */
trait ObnovujeHledaniFotek
{
    /**
     * Sloupce, které se skládají do hledaného textu fotek.
     *
     * @return list<string>
     */
    abstract protected function sloupceVHledani(): array;

    /**
     * Id fotek, které na záznam ukazují.
     *
     * @return iterable<int>
     */
    abstract protected function fotkyVHledani(): iterable;

    protected static function bootObnovujeHledaniFotek(): void
    {
        static::updated(function (Model $zaznam) {
            if ($zaznam->wasChanged($zaznam->sloupceVHledani())) {
                $zaznam->naplanujHledaniFotek();
            }
        });

        /*
         * Smazání — i natvrdo.
         *
         * Fotky se musí zjistit **před** smazáním: po něm vazby odejdou
         * kaskádou (nebo je `LideController::sluc` převede jinam) a nebylo by
         * podle čeho je najít. Přepočet se ale zařadí až po smazání, jinak by
         * úloha na synchronní frontě složila text ještě se starým jménem.
         * Album v koši z hledání zmizí (`MediaItem::albums()` ho nevrátí).
         */
        static::deleting(function (Model $zaznam) {
            try {
                $zaznam->fotkyPredSmazanim = collect($zaznam->fotkyVHledani())->map(fn ($id) => (int) $id)->all();
            } catch (\Throwable $e) {
                // Smazání kvůli odvozenému textu nezastavíme; dorovná ho `gallery:rebuild-search`.
                Log::warning('Fotky ke smazanému záznamu se nepodařilo zjistit', ['zaznam' => static::class.'#'.$zaznam->getKey(), 'chyba' => $e->getMessage()]);
            }
        });
        static::deleted(function (Model $zaznam) {
            $zaznam->naplanujHledaniFotek($zaznam->fotkyPredSmazanim);
            $zaznam->fotkyPredSmazanim = null;
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored(fn (Model $zaznam) => $zaznam->naplanujHledaniFotek());
        }
    }

    /**
     * Fotky zjištěné těsně před smazáním (viz `bootObnovujeHledaniFotek()`).
     *
     * @var list<int>|null
     */
    protected ?array $fotkyPredSmazanim = null;

    /** @param  iterable<int>|null  $fotky  bez nich se zjistí z aktuálních vazeb */
    public function naplanujHledaniFotek(?iterable $fotky = null): void
    {
        try {
            ObnovHledaniJob::naplanuj($fotky ?? $this->fotkyVHledani());
        } catch (\Throwable $e) {
            Log::warning('Přepočet hledaného textu fotek se nepodařilo zařadit', [
                'zaznam' => static::class.'#'.$this->getKey(),
                'chyba' => $e->getMessage(),
            ]);
        }
    }
}
