<?php

namespace App\Console\Commands;

use App\Models\MediaItem;
use App\Services\Media\FilenameMetadataService;
use App\Support\SpaceContext;
use Illuminate\Console\Command;

/**
 * Doplní datum pořízení z názvu souboru tam, kde chybí.
 *
 * Fotky, kterým EXIF někdo cestou odstranil — cokoli, co prošlo chatem — skončí v
 * archivu bez data a spadnou do hromádky na konci výpisu. Telefon jim ale dal jméno
 * `20260701_145133.jpg`, a to je pořád čas, který ukazovaly hodiny ve fotoaparátu.
 *
 * Ve výchozím stavu jen ukáže, co by udělal. Sahat na tisíce záznamů podle regulárního
 * výrazu bez možnosti se nejdřív podívat je způsob, jak si tiše rozházet celý archiv.
 */
class ReconcileDatesCommand extends Command
{
    protected $signature = 'gallery:reconcile-dates
                            {--apply : Skutečně zapsat; bez tohoto přepínače jen ukáže, co by se stalo}
                            {--space= : Jen tento prostor}
                            {--limit=1000 : Nejvýš tolik záznamů v jednom běhu}
                            {--rewrite : Přepsat i data, která už jsou vyplněná, pokud se od názvu liší}';

    protected $description = 'Doplní chybějící datum pořízení z názvu souboru.';

    public function handle(FilenameMetadataService $filenames): int
    {
        $zapsat = (bool) $this->option('apply');
        $prepsat = (bool) $this->option('rewrite');

        $query = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->whereNull('trashed_at')
            ->when($this->option('space'), fn ($q, $id) => $q->where('gallery_space_id', $id))
            ->when(! $prepsat, fn ($q) => $q->whereNull('taken_at'))
            ->limit((int) $this->option('limit'));

        $zmeneno = 0;
        $preskoceno = 0;

        foreach ($query->get() as $media) {
            $odvozene = $filenames->infer($media->original_filename ?? '', $media->media_type ?? 'photo');
            $datum = $odvozene['taken_at'] ?? null;

            if (! $datum) { $preskoceno++; continue; }

            // Při přepisu jen tehdy, když se to liší o víc než hodinu. Drobné rozdíly jsou
            // zaokrouhlení nebo vteřiny navíc, ne chyba, kterou by stálo za to opravovat.
            if ($prepsat && $media->taken_at && abs($media->taken_at->diffInMinutes($datum)) < 60) {
                $preskoceno++;

                continue;
            }

            $puvodni = $media->taken_at?->format('Y-m-d H:i') ?? '—';
            $this->line(sprintf(
                '  %-34s %s  →  %s',
                mb_strimwidth($media->original_filename ?? ('#' . $media->id), 0, 34, '…'),
                str_pad($puvodni, 16),
                $datum->format('Y-m-d H:i'),
            ));

            if ($zapsat) {
                $media->forceFill(['taken_at' => $datum])->save();
            }

            $zmeneno++;
        }

        $this->newLine();

        if ($zmeneno === 0) {
            $this->info('Nic k doplnění.' . ($preskoceno ? " Přeskočeno {$preskoceno} bez data v názvu." : ''));

            return self::SUCCESS;
        }

        $this->info($zapsat
            ? "Doplněno datum u {$zmeneno} položek." . ($preskoceno ? " Přeskočeno {$preskoceno}." : '')
            : "Doplnilo by se {$zmeneno} položek. Spusťte znovu s --apply.");

        return self::SUCCESS;
    }
}
