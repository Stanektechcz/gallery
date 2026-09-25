<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use App\Models\MediaVariant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

class ImageVariantService
{
    private ?ImageManager $manager = null;

    /** 100 Mpx — víc nemá ani fotka z 50Mpx fotoaparátu s rezervou; RGBA plátno ~400 MB. */
    public const MAX_PIXELU = 100_000_000;

    private const VARIANTS = [
        'placeholder' => ['width' => 64,   'quality' => 40],
        'thumbnail' => ['width' => 320,  'quality' => 80],
        'small' => ['width' => 800,  'quality' => 82],
        'medium' => ['width' => 1600, 'quality' => 85],
        'large' => ['width' => 2560, 'quality' => 88],
    ];

    /**
     * Ovladač obrázků, sestavený až při prvním čtení souboru.
     *
     * Intervention si zdraví ovladače ověřuje v konstruktoru a bez knihovny
     * vyhodí výjimku. Dokud se to dělalo tady, stačilo tuhle službu vyžádat —
     * a spadlo i to, co s obrázky nedělá nic: zpracování videa, nebo příkaz,
     * který chtěl nejdřív slušně oznámit, že knihovna chybí.
     *
     * GD neumí HEIC/HEIF. Imagick má proto přednost, kdykoliv je po ruce; na
     * serveru s libheif pak vzniknou z iPhonových fotek stejné náhledy jako
     * z JPEGů.
     */
    private function manager(): ImageManager
    {
        return $this->manager ??= extension_loaded('imagick')
            ? new ImageManager(new ImagickDriver)
            : new ImageManager(new GdDriver);
    }

    /**
     * Důvod, proč obrázek nedekódovat — nebo `null`, když je v pořádku.
     *
     * Pixelová bomba: PNG o pár kilobajtech s hlavičkou 30 000 × 30 000 px
     * si při dekódování řekne o ~3,6 GB. PHP skončí fatální chybou paměti,
     * kterou žádný `catch` nechytí — worker spadne a fronta úlohu pouští
     * znovu, dokud nevyčerpá pokusy. `getimagesize()` čte jen hlavičku.
     * Formát, kterému PHP nerozumí (HEIC, RAW), projde: jeho rozměry se tu
     * zjistit nedají a řeší ho Imagick s vlastními limity.
     */
    public static function prilisVelky(string $cesta): ?string
    {
        $rozmery = @getimagesize($cesta);
        if (! is_array($rozmery)) {
            return null;
        }

        [$sirka, $vyska] = [(int) $rozmery[0], (int) $rozmery[1]];

        return $sirka * $vyska > self::MAX_PIXELU
            ? "Obrázek je příliš velký na zpracování ({$sirka} × {$vyska} px, limit ".(self::MAX_PIXELU / 1_000_000).' Mpx)'
            : null;
    }

    /**
     * Generate all standard image variants for a media item.
     */
    public function generateAll(MediaItem $mediaItem, string $sourcePath): void
    {
        if ($duvod = self::prilisVelky($sourcePath)) {
            Log::warning("{$duvod} — varianty pro media #{$mediaItem->id} se nevytvoří.");

            return;
        }

        foreach (self::VARIANTS as $type => $config) {
            $this->generateVariant($mediaItem, $sourcePath, $type, $config);
        }

        $this->calculateBlurHashAndColor($mediaItem, $sourcePath);
    }

    public function generateVariant(MediaItem $mediaItem, string $sourcePath, string $type, array $config): ?MediaVariant
    {
        if ($duvod = self::prilisVelky($sourcePath)) {
            Log::warning("{$duvod} — varianta {$type} pro media #{$mediaItem->id} se nevytvoří.");

            return null;
        }

        try {
            /*
             * API Intervention Image 4.
             *
             * Volalo se `read()` a `toWebp()` z verze 3. Ve verzi 4 (ta je
             * v composer.lock) ty metody nejsou — každá varianta skončila
             * výjimkou, kterou `catch` níž jen zalogoval. Nahrané fotky tak
             * neměly jediný náhled a mřížka stahovala originály.
             */
            $image = $this->manager()->decodePath($sourcePath);
            $image->scaleDown(width: $config['width']);

            $ext = 'webp'; // prefer WebP
            // Keep every locally served file in the same directory as its
            // original. The public file proxy and upload pipeline both use
            // media/{uuid}; using a second directory here caused variants to
            // exist in the database while their URLs pointed at missing files.
            $dir = "media/{$mediaItem->uuid}";
            $filename = "{$type}.{$ext}";
            $path = "{$dir}/{$filename}";

            // `strip`: náhled nepotřebuje EXIF — a GPS v něm by šla ven se sdíleným odkazem.
            $encoded = $image->encode(new WebpEncoder(quality: $config['quality'], strip: true));
            $contents = $encoded->toString();
            if (! Storage::disk('public')->put($path, $contents, 'public')) {
                throw new \RuntimeException("Variantu se nepodařilo uložit: {$path}");
            }

            return MediaVariant::updateOrCreate(
                ['media_item_id' => $mediaItem->id, 'type' => $type],
                [
                    'disk' => 'public',
                    'path' => $path,
                    'width' => $image->width(),
                    'height' => $image->height(),
                    'size_bytes' => strlen($contents),
                    'format' => 'webp',
                    'mime_type' => 'image/webp',
                    'aspect_ratio' => $image->height() > 0 ? round($image->width() / $image->height(), 4) : null,
                ]
            );
        } catch (\Throwable $e) {
            Log::error("Failed to generate {$type} variant for media #{$mediaItem->id}", ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function calculateBlurHashAndColor(MediaItem $mediaItem, string $sourcePath): void
    {
        if (self::prilisVelky($sourcePath)) {
            return;
        }

        try {
            $image = $this->manager()->decodePath($sourcePath);
            $image->scaleDown(width: 64); // tiny version for hash/color

            // Dominant color via simple pixel sampling
            $colors = [];
            $w = $image->width();
            $h = $image->height();
            $step = max(1, (int) ($w / 10));

            for ($x = 0; $x < $w; $x += $step) {
                for ($y = 0; $y < $h; $y += $step) {
                    $pixel = $image->colorAt($x, $y);
                    $colors[] = [$pixel->red()->value(), $pixel->green()->value(), $pixel->blue()->value()];
                }
            }

            if (! empty($colors)) {
                $avg = array_map(fn ($chan) => (int) (array_sum(array_column($colors, $chan)) / count($colors)), [0, 1, 2]);
                $hex = sprintf('#%02x%02x%02x', $avg[0], $avg[1], $avg[2]);
            } else {
                $hex = '#888888';
            }

            // Update the placeholder variant with dominant color
            MediaVariant::where('media_item_id', $mediaItem->id)
                ->where('type', 'placeholder')
                ->update(['dominant_color' => $hex]);

        } catch (\Throwable $e) {
            Log::warning("Color extraction failed for media #{$mediaItem->id}", ['error' => $e->getMessage()]);
        }
    }
}
