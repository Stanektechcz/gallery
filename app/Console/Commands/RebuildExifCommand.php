<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\MediaItem;
use App\Services\ExifExtractorService;
use App\Services\Media\MediaPurger;
use App\Support\SpaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RebuildExifCommand extends Command
{
    protected $signature = 'gallery:exif
        {--all : Re-extract even items that already have GPS}
        {--clean-orphans : Vypíše položky, po kterých nezbyl soubor}
        {--opravdu : Sirotky z `--clean-orphans` opravdu smaže (jinak jen výpis)}';

    protected $description = 'Re-extract EXIF (GPS, date, camera) from local files using Imagick/exiftool';

    public function __construct(private readonly MediaPurger $mazani)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Úklid je samostatná práce, ne předehra ke čtení EXIF z celé knihovny.
        // Dřív se po něm projela celá knihovna, což u ručního úklidu nikdo nečeká.
        if ($this->option('clean-orphans')) {
            $this->cleanOrphans();

            return 0;
        }

        $query = MediaItem::where('media_type', 'photo')
            ->whereNull('trashed_at');

        if (! $this->option('all')) {
            // Only items missing GPS
            $query->whereNull('latitude');
        }

        $total = $query->count();
        $this->info("Found {$total} photo items to process.");

        if ($total === 0) {
            $this->info('Nothing to do. Use --all to re-extract from all photos.');

            return 0;
        }

        $bar = $this->output->createProgressBar($total);
        $done = 0;
        $fail = 0;
        $nogps = 0;

        $exiftoolPath = config('gallery.exiftool_path', '/usr/bin/exiftool');

        $query->with('variants')->each(function (MediaItem $media) use ($bar, &$done, &$fail, &$nogps, $exiftoolPath) {
            // Find local source file
            $originalVar = $media->variants()->where('type', 'original')->first();

            /*
             * Jen místní disky.
             *
             * Zrcadlení zapisuje do `media_variants.disk` jméno poskytovatele
             * (`dropbox`, `onedrive`, `webdav`), které v `config/filesystems.php`
             * není — `Storage::disk()` na něm vyhodí výjimku a shodí celý
             * průchod kvůli jediné fotce. EXIF se stejně čte z místního souboru.
             */
            $disk = $originalVar?->disk ?: 'public';
            $sourcePath = $originalVar && in_array($disk, ['public', 'local'], true)
                ? Storage::disk($disk)->path($originalVar->path)
                : null;

            if (! $sourcePath || ! file_exists($sourcePath)) {
                $this->newLine();
                $this->line("  <comment>No local file for #{$media->id} {$media->original_filename}</comment>");
                $bar->advance();
                $fail++;

                return;
            }

            $updates = $this->extractExif($sourcePath, $exiftoolPath);

            if ($updates) {
                $media->update($updates);
                if (isset($updates['latitude'])) {
                    $done++;
                    $this->newLine();
                    $this->line("  <info>#{$media->id} GPS: {$updates['latitude']}, {$updates['longitude']}</info>");
                } else {
                    $nogps++;
                }
            } else {
                $nogps++;
            }

            $bar->advance();
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. With GPS: {$done}, No GPS in file: {$nogps}, Failed: {$fail}");

        return 0;
    }

    /**
     * Položky, po kterých nezbyl soubor.
     *
     * Dřív se bralo „nemá `drive_file_id` a originál není na místním disku" —
     * a hned `forceDelete()`. Do té množiny ale patří dvě skupiny, které
     * sirotci nejsou:
     *
     * - fotky **zrcadlené do jiného cloudu** (Dropbox, OneDrive, WebDAV).
     *   `drive_file_id` mají prázdné z principu a jejich originál na místním
     *   disku být nemá;
     * - položky, které se **právě nahrávají** a variantu originálu ještě
     *   nemají. U nich „soubor tu není" znamená „ještě tam není".
     *
     * Navíc se mazalo bez zkoušky nanečisto, bez potvrzení a bez zápisu do
     * protokolu. Výchozí chování je proto výpis; smaže se až s `--opravdu`.
     *
     * Položka s **kopií v cloudu** (`drive_file_id` nebo varianta `cloud_copy`)
     * se nemaže nikdy, jen se vypíše jako obnovitelná. Chybějící originál tu
     * umí způsobit i nepřipojený nebo špatně nastavený disk — a smazání přes
     * `MediaPurger` by pak zařadilo mazání i té kopie v cloudu, která je v tu
     * chvíli jediná, co zbylo.
     */
    private function cleanOrphans(): void
    {
        $opravdu = (bool) $this->option('opravdu');
        $sirotku = 0;
        $obnovitelne = 0;

        MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            // Rozdělaná položka nemá být čím posuzovaná.
            ->whereNotIn('storage_status', ['uploading', 'processing'])
            ->with('variants')
            ->each(function (MediaItem $media) use ($opravdu, &$sirotku, &$obnovitelne) {
                $original = $media->variants->firstWhere('type', 'original');

                // Bez varianty originálu se nedá říct, že soubor chybí — jen
                // to, že se ještě nezaložila.
                if ($original === null) {
                    return;
                }

                $disk = $original->disk ?: 'public';

                // Cizí cloud: `Storage::disk('dropbox')` tu ani není nastavený,
                // takže se na něj nedá zeptat — a hlavně tam ten soubor patří.
                if (! in_array($disk, ['public', 'local'], true)) {
                    return;
                }

                if (file_exists(Storage::disk($disk)->path($original->path))) {
                    return;
                }

                // Jméno z trezoru ani do výpisu — výstup příkazu končí v logu
                // (stejně jako u `gallery:purge-trash`).
                $jmeno = $media->is_hidden ? '(trezor)' : $media->original_filename;

                if ($media->drive_file_id || $media->variants->contains('type', 'cloud_copy')) {
                    $obnovitelne++;
                    $this->line("  Obnovitelné z cloudu: #{$media->id} {$jmeno}");

                    return;
                }

                $sirotku++;
                $this->line('  '.($opravdu ? 'Mažu' : 'Smazal bych').": #{$media->id} {$jmeno}");

                if (! $opravdu) {
                    return;
                }

                // Jméno jen mimo trezor: přehled „Dnes" jména z protokolu vypisuje.
                AuditLog::record('media.orphan.purged', $media, ($media->is_hidden ? [] : [
                    'filename' => $media->original_filename,
                ]) + [
                    'duvod' => 'soubor originálu na disku chybí',
                ]);

                // Přes `MediaPurger`, ne vlastní mazání: jedno místo pro soubory,
                // náhledy a složku nahrávání. Kopie v cloudu tu už být nemůže.
                $this->mazani->purge($media);
                $media->forceDelete();
            });

        $this->info($opravdu
            ? "Smazáno {$sirotku} položek bez souboru."
            : "Ke smazání: {$sirotku} položek. Spusťte s `--opravdu`, ať se to provede.");

        if ($obnovitelne > 0) {
            // Příkaz, který by originál z cloudu stáhl zpět, zatím není —
            // proto aspoň kam se podívat a co nedělat.
            $this->warn("Obnovitelné z cloudu (nesmazáno): {$obnovitelne} — originál chybí jen na serveru, kopie v cloudu je. "
                .'Nejdřív zkontrolujte připojení disku serveru a stránku Obnova (/recovery); soubor stáhněte z cloudu zpět ručně.');
        }
    }

    private function extractExif(string $sourcePath, string $exiftoolPath): array
    {
        return (new ExifExtractorService)->extract($sourcePath);
    }
}
