<?php

namespace App\Console\Commands;

use App\Models\DuplicateGroup;
use App\Models\MediaItem;
use App\Services\Media\PerceptualHashService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Hledání duplicit — vždy uvnitř jednoho prostoru.
 *
 * Týdenní běh jde bez `--space` a dřív bral knihovny všech dvojic naráz:
 * dvě dvojice s touž přeposlanou fotkou skončily v jednom nálezu, první
 * dvojice u něj viděla cizí název souboru i místo a „Sloučit" poslalo fotku
 * té druhé do koše, odkud ji po třiceti dnech `gallery:purge-trash` smazal.
 * Proto se teď prochází prostor po prostoru; nález i hledání „už existujícího"
 * nálezu patří vždy jen tomu jednomu prostoru.
 */
class GalleryScanDuplicatesCommand extends Command
{
    protected $signature = 'gallery:scan-duplicates {--space= : Gallery space ID}';

    protected $description = 'Scan for duplicate media using hash-based detection (no AI)';

    /** Kolik bitů se smí lišit, aby dva snímky platily za podobné. */
    private const PRAH_PODOBNOSTI = 8;

    public function handle(PerceptualHashService $hashService): int
    {
        $this->info('Scanning for duplicates...');

        $prostory = $this->option('space')
            ? collect([(int) $this->option('space')])
            : $this->zaklad()->distinct()->orderBy('gallery_space_id')->pluck('gallery_space_id')->map(fn ($id) => (int) $id);

        $shodnych = 0;
        $podobnych = 0;

        foreach ($prostory as $prostor) {
            $shodnych += $this->shodne($prostor);
            $podobnych += $this->podobne($prostor, $hashService);
        }

        $this->info("Found {$shodnych} exact duplicate groups.");
        $this->info("Found {$podobnych} perceptually similar pairs.");

        return Command::SUCCESS;
    }

    /**
     * Co se vůbec smí porovnávat.
     *
     * Fotky z trezoru ne: seznam duplicit se kreslí mimo trezor s názvem
     * souboru i místem, a nález by tak prozradil, co v zamčeném trezoru leží.
     */
    private function zaklad(): Builder
    {
        return MediaItem::query()
            ->where('status', 'ready')
            ->whereNull('trashed_at')
            ->where('is_hidden', false);
    }

    /** Level 1: shodný SHA-256 uvnitř jednoho prostoru. */
    private function shodne(int $prostor): int
    {
        // Seskupuje databáze — do paměti se nenačítá celá knihovna, jen otisky,
        // které se v prostoru opakují.
        $otisky = $this->zaklad()
            ->where('gallery_space_id', $prostor)
            ->whereNotNull('sha256')
            ->groupBy('sha256')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('sha256');

        $nalezu = 0;

        foreach ($otisky as $otisk) {
            $existuje = DuplicateGroup::where('gallery_space_id', $prostor)
                ->whereHas('mediaItems', fn ($q) => $q->where('sha256', $otisk))
                ->exists();

            if ($existuje) {
                continue;
            }

            $skupina = DuplicateGroup::create([
                'uuid' => Str::uuid(),
                'gallery_space_id' => $prostor,
                'match_type' => 'exact',
                'detected_at' => now(),
            ]);
            $skupina->mediaItems()->attach(
                $this->zaklad()->where('gallery_space_id', $prostor)->where('sha256', $otisk)->pluck('id'),
            );
            $nalezu++;
        }

        return $nalezu;
    }

    /**
     * Level 2: podobný vzhled (jen fotky), dvojice po dvojici — ale jen v jednom prostoru.
     *
     * Porovnání je kvadratické; přes celou databázi rostlo s druhou mocninou
     * všech fotek všech dvojic. Načítají se jen sloupce, které porovnání potřebuje.
     */
    private function podobne(int $prostor, PerceptualHashService $hashService): int
    {
        /** @var Collection<int, MediaItem> $fotky */
        $fotky = $this->zaklad()
            ->where('gallery_space_id', $prostor)
            ->where('media_type', 'photo')
            ->whereNotNull('perceptual_hash')
            ->orderBy('id')
            ->get(['id', 'sha256', 'perceptual_hash'])
            ->values();

        $nalezu = 0;
        $pocet = $fotky->count();

        for ($i = 0; $i < $pocet; $i++) {
            for ($j = $i + 1; $j < $pocet; $j++) {
                $a = $fotky[$i];
                $b = $fotky[$j];

                // Shodné už zachytil první krok.
                if ($a->sha256 !== null && $a->sha256 === $b->sha256) {
                    continue;
                }

                if (! $hashService->areSimilar($a->perceptual_hash, $b->perceptual_hash, self::PRAH_PODOBNOSTI)) {
                    continue;
                }

                $existuje = DuplicateGroup::where('gallery_space_id', $prostor)
                    ->where('match_type', 'similar')
                    ->whereHas('mediaItems', fn ($q) => $q->whereIn('media_items.id', [$a->id, $b->id]))
                    ->exists();

                if ($existuje) {
                    continue;
                }

                $skupina = DuplicateGroup::create([
                    'uuid' => Str::uuid(),
                    'gallery_space_id' => $prostor,
                    'match_type' => 'similar',
                    'detected_at' => now(),
                ]);
                $skupina->mediaItems()->attach([$a->id, $b->id]);
                $nalezu++;
            }
        }

        return $nalezu;
    }
}
