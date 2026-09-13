<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

class MediaVariant extends Model
{
    protected $fillable = [
        'media_item_id',
        'type',
        'disk',
        'path',
        'width',
        'height',
        'size_bytes',
        'format',
        'mime_type',
        'blur_hash',
        'dominant_color',
        'aspect_ratio',
    ];

    protected $appends = ['url'];

    protected function casts(): array
    {
        return ['aspect_ratio' => 'float'];
    }

    public function mediaItem()
    {
        return $this->belongsTo(MediaItem::class);
    }

    public function getUrlAttribute(): string
    {
        if ($this->disk === 'public') {
            return self::proxyUrl($this->path);
        }

        return url('variants/'.$this->path);
    }

    /**
     * A URL for a public-disk file that carries no file extension.
     *
     * The extension is the whole problem. The web server matches image suffixes and
     * serves them straight from disk; when the file is not there it answers 404 and only
     * then hands the request to PHP, which streams the picture perfectly — under the 404
     * it has already committed to. The browser sees an error and draws nothing, so a
     * working gallery looked empty.
     *
     * Moving the extension into a query parameter keeps the path unmistakably dynamic,
     * so the request reaches the application with its own status intact. It also costs
     * nothing: the response says what it is in Content-Type, which is what browsers
     * actually read.
     */
    /**
     * Podepsaná adresa souboru z veřejného disku.
     *
     * `/files/…` dřív vydávalo soubor komukoli, kdo znal cestu. Teď projde jen
     * podpis nebo přihlášený člen prostoru (`MediaFileController::smi`), takže
     * adresa, kterou aplikace vloží do obrázku, sdíleného odkazu nebo notifikace,
     * nese podpis. Platí do konce zítřka — přes den se nemění, prohlížeč si
     * obrázek podrží v mezipaměti, a zrušený odkaz přestane otevírat soubory
     * nejpozději druhý den.
     */
    public static function proxyUrl(string $path): string
    {
        $path = ltrim($path, '/');
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $parametry = $extension === ''
            ? ['path' => $path]
            : ['path' => substr($path, 0, -(strlen($extension) + 1)), 'ext' => $extension];

        return URL::temporarySignedRoute('media.file', CarbonImmutable::tomorrow()->endOfDay(), $parametry);
    }
}
