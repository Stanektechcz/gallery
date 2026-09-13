<?php

namespace App\Services\Media;

use App\Models\MediaEdit;
use App\Models\MediaItem;
use App\Models\MediaVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

/**
 * Otočení a výřez fotky z prohlížeče — nedestruktivně.
 *
 * Prohlížeč „ukládal úpravu jako novou verzi" tak, že si do stavu zapsal úhel
 * a obrázek natáčel přes CSS. Mřížka, stažený soubor, sdílený odkaz i druhý
 * telefon viděly fotku neotočenou. Teď vznikne upravená verze na serveru
 * (`edited_preview` pro prohlížeč, `edited_thumbnail` pro mřížku); originál
 * zůstává nedotčený a návrat k němu upravené verze jen smaže.
 *
 * Výřez je tentýž rámeček, který prohlížeč kreslí přes fotku
 * (`inset: 9% 12%`) — co člověk vidí v rámečku, to zůstane.
 */
class UpravaFotky
{
    private const VYREZ_X = 0.12;

    private const VYREZ_Y = 0.09;

    /** @return bool false, když aplikace nemá z čeho upravenou verzi vyrobit */
    public function uloz(MediaItem $media, int $otoceni, bool $vyrez, User $kdo): bool
    {
        $otoceni = (($otoceni % 360) + 360) % 360;
        $otoceni = (int) (round($otoceni / 90) * 90) % 360;

        if ($otoceni === 0 && ! $vyrez) {
            $this->zrus($media);

            return true;
        }

        $zdroj = $this->zdroj($media);

        if ($zdroj === null) {
            return false;
        }

        $obraz = $this->spravce()->decodePath($zdroj);

        if ($otoceni !== 0) {
            // Kladný úhel otáčí po směru hodinových ručiček — stejně jako CSS `rotate()`.
            $obraz->rotate($otoceni);
        }

        if ($vyrez) {
            $sirka = $obraz->width();
            $vyska = $obraz->height();
            $obraz->crop(
                (int) round($sirka * (1 - 2 * self::VYREZ_X)),
                (int) round($vyska * (1 - 2 * self::VYREZ_Y)),
                (int) round($sirka * self::VYREZ_X),
                (int) round($vyska * self::VYREZ_Y),
            );
        }

        DB::transaction(function () use ($media, $obraz, $otoceni, $vyrez, $kdo) {
            $this->varianta($media, $obraz, 'edited_preview', 2560, 88);
            $this->varianta($media, $obraz, 'edited_thumbnail', 320, 80);

            $posledni = (int) $media->edits()->max('version');
            $media->edits()->where('is_current', true)->update(['is_current' => false]);

            // `forceFill`: `created_at` není ve `$fillable` a model nemá časová razítka.
            (new MediaEdit)->forceFill([
                'media_item_id' => $media->id,
                'version' => $posledni + 1,
                'operations_json' => array_values(array_filter([
                    $otoceni ? ['type' => 'rotate', 'degrees' => $otoceni] : null,
                    $vyrez ? ['type' => 'crop', 'inset_x' => self::VYREZ_X, 'inset_y' => self::VYREZ_Y] : null,
                ])),
                'is_current' => true,
                'created_by' => $kdo->id,
                'created_at' => now(),
            ])->save();
        });

        return true;
    }

    /** Zpět na originál: upravené verze pryč, originál nikdy nebyl dotčen. */
    public function zrus(MediaItem $media): void
    {
        $media->variants()->whereIn('type', ['edited_preview', 'edited_thumbnail'])->get()
            ->each(function (MediaVariant $v) {
                Storage::disk($v->disk ?: 'public')->delete($v->path);
                $v->delete();
            });

        $hadUpravu = $media->edits()->where('is_current', true)->exists();
        $media->edits()->where('is_current', true)->update(['is_current' => false]);

        // Návrat k originálu je taky zásah: záznam posune verzi v adrese náhledu,
        // jinak by prohlížeč dál ukazoval upravený obrázek z paměti.
        if ($hadUpravu) {
            (new MediaEdit)->forceFill([
                'media_item_id' => $media->id,
                'version' => (int) $media->edits()->max('version') + 1,
                'operations_json' => [],
                'is_current' => false,
                'created_by' => auth()->id(),
                'created_at' => now()->addSecond(),
            ])->save();
        }
    }

    /** Největší soubor, který aplikace u sebe má — originál, jinak velký náhled. */
    private function zdroj(MediaItem $media): ?string
    {
        foreach (['original', 'large', 'medium'] as $typ) {
            $v = $media->variants()->where('type', $typ)->first();

            if ($v === null || ! $media->media_type || $media->media_type !== 'photo') {
                continue;
            }

            $cesta = rescue(fn () => Storage::disk($v->disk ?: 'public')->path($v->path), null, false);

            if ($cesta && is_file($cesta)) {
                return $cesta;
            }
        }

        return null;
    }

    private function varianta(MediaItem $media, $obraz, string $typ, int $sirka, int $kvalita): void
    {
        $kopie = clone $obraz;
        $kopie->scaleDown(width: $sirka);
        $obsah = $kopie->encode(new WebpEncoder(quality: $kvalita, strip: true))->toString();
        $cesta = "media/{$media->uuid}/{$typ}.webp";

        Storage::disk('public')->put($cesta, $obsah);

        MediaVariant::updateOrCreate(
            ['media_item_id' => $media->id, 'type' => $typ],
            [
                'disk' => 'public',
                'path' => $cesta,
                'width' => $kopie->width(),
                'height' => $kopie->height(),
                'size_bytes' => strlen($obsah),
                'format' => 'webp',
                'mime_type' => 'image/webp',
                'aspect_ratio' => $kopie->height() > 0 ? round($kopie->width() / $kopie->height(), 4) : null,
            ],
        );

        // Adresa náhledu nese čas úpravy (`v`); updateOrCreate beze změny sloupců čas neposune.
        MediaVariant::where('media_item_id', $media->id)->where('type', $typ)->update(['updated_at' => now()]);
    }

    private function spravce(): ImageManager
    {
        return extension_loaded('imagick')
            ? new ImageManager(new ImagickDriver)
            : new ImageManager(new GdDriver);
    }
}
