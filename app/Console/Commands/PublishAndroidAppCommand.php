<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class PublishAndroidAppCommand extends Command
{
    protected $signature = 'gallery:publish-android-app
        {apk : Cesta k podepsanému release APK}
        {--app-version= : Veřejné číslo verze, např. 1.2.0}';

    protected $description = 'Bezpečně zveřejní podepsané Android APK na stabilním odkazu /app/android/download';

    public function handle(): int
    {
        $source = realpath((string) $this->argument('apk'));
        if ($source === false || ! is_file($source) || ! is_readable($source)) {
            $providedPath = (string) $this->argument('apk');
            $this->error("APK soubor '{$providedPath}' neexistuje nebo jej nelze číst.");
            $this->newLine();
            $this->line('Tento příkaz APK nevytváří; publikuje již sestavený a podepsaný soubor.');
            $this->line('1. Aktualizujte repozitář příkazem git pull --ff-only.');
            $this->line('2. Ověřte soubor release-assets/android/maki-gallery-1.0.0.apk.');
            $this->line('3. Spusťte příkaz s cestou $PWD/release-assets/android/maki-gallery-1.0.0.apk.');
            return self::FAILURE;
        }

        if (strtolower((string) pathinfo($source, PATHINFO_EXTENSION)) !== 'apk') {
            $this->error('Publikovat lze pouze soubor s příponou .apk.');
            return self::FAILURE;
        }

        $header = file_get_contents($source, false, null, 0, 4);
        if ($header === false || ! str_starts_with($header, "PK")) {
            $this->error('Soubor nemá platnou APK/ZIP hlavičku.');
            return self::FAILURE;
        }

        $version = trim((string) ($this->option('app-version') ?: config('mobile.android.version', '1.0.0')));
        if ($version === '' || preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$/', $version) !== 1) {
            $this->error('Verze obsahuje nepovolené znaky.');
            return self::FAILURE;
        }

        $sha256 = hash_file('sha256', $source);
        $size = filesize($source);
        if ($sha256 === false || $size === false) {
            $this->error('Nepodařilo se spočítat kontrolní údaje APK.');
            return self::FAILURE;
        }

        $diskName = (string) config('mobile.android.disk', 'local');
        $target = (string) config('mobile.android.path', 'mobile/maki-gallery.apk');
        $metadataTarget = (string) config('mobile.android.metadata_path', 'mobile/maki-gallery.json');
        $temporaryTarget = dirname($target).'/.'.basename($target).'.'.Str::uuid().'.upload';
        $stream = fopen($source, 'rb');
        if ($stream === false) {
            $this->error('APK se nepodařilo otevřít.');
            return self::FAILURE;
        }

        try {
            $disk = Storage::disk($diskName);
            if (! $disk->put($temporaryTarget, $stream)) throw new \RuntimeException('Nahrání do dočasného souboru selhalo.');
            if (! $disk->move($temporaryTarget, $target)) throw new \RuntimeException('Aktivace nového APK selhala.');
            if (! $disk->put($metadataTarget, json_encode([
                'version' => $version,
                'sha256' => $sha256,
                'size_bytes' => $size,
                'published_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) {
                throw new \RuntimeException('Uložení metadat APK selhalo.');
            }
        } catch (Throwable $error) {
            $this->error('Publikování selhalo: '.$error->getMessage());
            return self::FAILURE;
        } finally {
            if (is_resource($stream)) fclose($stream);
            try {
                Storage::disk($diskName)->delete($temporaryTarget);
            } catch (Throwable) {
                // Best-effort cleanup; a failed cleanup must not hide the publication result.
            }
        }

        $this->newLine();
        $this->info("Android aplikace {$version} byla zveřejněna.");
        $this->line('Stažení: '.route('mobile-app.android.download'));
        $this->line('SHA-256: '.$sha256);
        $this->warn('Příkaz ověřuje strukturu souboru, nikoli důvěryhodnost podpisu. Před publikováním použijte apksigner verify.');

        return self::SUCCESS;
    }
}
