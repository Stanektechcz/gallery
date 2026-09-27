<?php

namespace App\Console\Commands;

use App\Models\MediaItem;
use App\Services\Hledani\ObnovaHledani;
use App\Support\SpaceContext;
use Illuminate\Console\Command;

/**
 * Složit `search_text` všech fotek znovu.
 *
 * Jednou po nasazení nového skládání textu (měsíc, rok, roční doba, alba,
 * místo z prototypu, kopie bez diakritiky) — staré fotky ho jinak dostanou
 * až při další úpravě. A kdykoli potom, když se text s vazbami rozejde.
 * Po dávkách, vazby naráz pro celou dávku; mění se jen řádky, kterým se
 * text opravdu změnil.
 */
class RebuildSearchCommand extends Command
{
    protected $signature = 'gallery:rebuild-search {--space= : Jen jeden prostor galerie (id)}';

    protected $description = 'Znovu složí hledaný text (search_text) u všech fotek';

    public function handle(ObnovaHledani $obnova): int
    {
        $prostor = $this->option('space');

        if ($prostor !== null && ! ctype_digit((string) $prostor)) {
            $this->error('--space musí být číslo prostoru galerie.');

            return self::FAILURE;
        }

        $prosel = 0;
        $zmeneno = 0;

        MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->when($prostor !== null, fn ($q) => $q->where('gallery_space_id', (int) $prostor))
            ->with(ObnovaHledani::VAZBY)
            ->chunkById(ObnovaHledani::DAVKA, function ($media) use ($obnova, &$prosel, &$zmeneno) {
                $prosel += $media->count();
                $zmeneno += $obnova->zapis($media);
            });

        $this->info("Prošlo {$prosel} fotek, nový hledaný text dostalo {$zmeneno}.");

        return self::SUCCESS;
    }
}
