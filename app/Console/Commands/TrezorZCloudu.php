<?php

namespace App\Console\Commands;

use App\Models\MediaItem;
use App\Services\Media\KopieTrezoru;
use App\Support\SpaceContext;
use Illuminate\Console\Command;

/**
 * Odebere z cloudu kopie položek, které leží v trezoru.
 *
 * „Fotky z trezoru nejdou na cloud" (rozhodnutí 27. 9. 2026). Nové přesuny
 * do trezoru kopie odebírají samy; tenhle příkaz dočistí položky schované
 * dřív a ty, na které upozorní `gallery:doctor`.
 *
 * Bez `--provest` jen počítá. S ním zařadí odebrání (`KopieTrezoru::vlozeno`)
 * **jen u položek s ověřeným originálem na serveru** — neověřené vypíše
 * s důvodem a nechá být: kopie v cloudu může být jediná, co zbylo.
 *
 * Výpis nese id a uuid, nikdy jméno souboru — obsah trezoru do terminálu
 * ani do logu plánovače nepatří.
 */
class TrezorZCloudu extends Command
{
    protected $signature = 'gallery:trezor-z-cloudu
        {--space= : Jen jeden prostor podle id}
        {--limit=500 : Kolik položek nejvýš projít}
        {--provest : Opravdu zařadit odebrání kopií (jinak jen výpis)}';

    protected $description = 'Odebere z cloudu kopie položek v trezoru (jen s ověřeným originálem na serveru)';

    public function handle(KopieTrezoru $trezor): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $polozky = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->withTrashed()
            ->where('is_hidden', true)
            ->when($this->option('space'), fn ($q, $id) => $q->where('gallery_space_id', (int) $id))
            ->where(fn ($q) => $q->whereNotNull('drive_file_id')
                ->orWhere('storage_status', 'uploading')
                ->orWhereHas('variants', fn ($v) => $v->where('type', 'cloud_copy')->where('disk', '!=', 'public')))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $overene = [];
        $nahrava = 0;
        $bezKopie = 0;
        $neovereno = array_fill_keys(array_keys(KopieTrezoru::POPISY), 0);

        foreach ($polozky as $media) {
            if ($media->nahravaNaDisk()) {
                $nahrava++;

                continue;
            }

            $maKopii = $media->drive_file_id
                || $media->variants()->where('type', 'cloud_copy')->where('disk', '!=', 'public')->exists();

            if (! $maKopii) {
                // Zaseknuté nahrávání bez souboru v cloudu — není co odebrat.
                $bezKopie++;

                continue;
            }

            if ($duvod = $trezor->procNelzeOverit($media)) {
                $neovereno[$duvod]++;
                $this->line("  #{$media->id} {$media->uuid} (trezor) — ponecháno: ".KopieTrezoru::popis($duvod));

                continue;
            }

            $overene[] = $media->id;
        }

        $this->line('Prošlo položek v trezoru (kopie v cloudu nebo nahrávání): '.$polozky->count());
        $this->line('Lze odebrat (originál ověřen): '.count($overene));
        $this->line('Neověřeno, kopie zůstává: '.array_sum($neovereno));

        foreach ($neovereno as $duvod => $kolik) {
            if ($kolik > 0) {
                $this->line('  '.KopieTrezoru::popis($duvod).': '.$kolik);
            }
        }

        $this->line('Nahrává se na Disk (zkuste později): '.$nahrava);

        if ($bezKopie > 0) {
            $this->line('Bez kopie v cloudu (zaseknuté nahrávání): '.$bezKopie);
        }

        if (! $this->option('provest')) {
            $this->info('Nic nezměněno. Odebrat ověřené: php artisan gallery:trezor-z-cloudu --provest');

            return self::SUCCESS;
        }

        $trezor->vlozeno($overene);
        $this->info('Zařazeno k odebrání z cloudu: '.count($overene));

        return self::SUCCESS;
    }
}
