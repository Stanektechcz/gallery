<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGallerySpace;
use App\Support\FulltextDotaz;
use App\Support\Tabulky;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MediaItem extends Model
{
    use BelongsToGallerySpace, HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'gallery_space_id',
        'owner_user_id',
        'uploaded_by',
        'primary_album_id',
        'drive_file_id',
        'drive_parent_folder_id',
        'original_filename',
        'safe_filename',
        'display_title',
        'extension',
        'mime_type',
        'media_type',
        'size_bytes',
        'sha256',
        'md5',
        'perceptual_hash',
        'perceptual_hash_bits',
        'width',
        'height',
        'duration_ms',
        'bitrate',
        'frame_rate',
        'video_codec',
        'audio_codec',
        'taken_at',
        'taken_at_timezone',
        // Rok odvozený při datování, ne změřený přístrojem.
        'taken_at_estimated',
        'uploaded_at',
        'imported_at',
        'latitude',
        'longitude',
        'altitude',
        'location_name',
        'location_country',
        'location_source',
        'orientation',
        'camera_make',
        'camera_model',
        'lens_model',
        'iso',
        'aperture',
        'shutter_speed',
        'focal_length',
        'rating',
        'description',
        'caption',
        'notes',
        'status',
        'processing_stage',
        'processing_progress',
        'storage_status',
        'is_favorite',
        'is_archived',
        'is_hidden',
        // Extended media format fields
        'is_panorama',
        'is_360',
        'panorama_projection',
        'is_raw',
        'raw_format',
        'live_photo_content_id',
        'live_photo_role',
        'live_photo_pair_id',
        'trashed_at',
        'purge_after',
        // Návrh ke smazání čeká na souhlas druhého z dvojice (`MazaniFotek`).
        'trash_requested_by',
        'trash_requested_at',
        'trashed_by',
        'last_verified_at',
        'processing_error',
        'search_text',
    ];

    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
            'uploaded_at' => 'datetime',
            'imported_at' => 'datetime',
            'trashed_at' => 'datetime',
            'purge_after' => 'datetime',
            'trash_requested_by' => 'integer',
            'trash_requested_at' => 'datetime',
            'trashed_by' => 'integer',
            'last_verified_at' => 'datetime',
            'taken_at_estimated' => 'boolean',
            'is_favorite' => 'boolean',
            'is_archived' => 'boolean',
            'is_hidden' => 'boolean',
            'is_panorama' => 'boolean',
            'is_360' => 'boolean',
            'is_raw' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'altitude' => 'float',
        ];
    }

    /**
     * Kdo na fotku ukazuje, aniž by to hlídal cizí klíč.
     *
     * Devět sloupců v osmi tabulkách — obálka alba, osoby, cesty, fotoknihy
     * a stohu, druhá půlka živé fotky, výsledek nahrávání, doklad hosta
     * a záznam o stažení. `recipes.cover_media_id` cizí klíč s `nullOnDelete()`
     * má, takže tady chybí z přehlédnutí, ne ze záměru.
     *
     * @var array<string, string>
     */
    private const ODKAZY = [
        'albums' => 'cover_media_id',
        'people' => 'cover_media_id',
        'trips' => 'cover_media_id',
        'photo_books' => 'cover_media_id',
        'media_stacks' => 'cover_media_id',
        'upload_sessions' => 'resulting_media_id',
        'guest_uploads' => 'media_item_id',
        'share_access_logs' => 'media_item_id',
        'media_items' => 'live_photo_pair_id',
    ];

    protected static function booted(): void
    {
        static::creating(fn (MediaItem $m) => $m->uuid ??= (string) Str::uuid());

        /*
         * Po trvalém smazání nesmí nikde zbýt odkaz na tuhle fotku.
         *
         * Dokud koš mazal měkce, řádek v `media_items` zůstával a odkaz pořád
         * na něco ukazoval. Od chvíle, kdy se maže doopravdy, by ukazoval do
         * prázdna: album by si jako obálku vzalo nic a obrazovka by se ptala
         * na fotku, která neexistuje.
         *
         * Cizí klíče s `nullOnDelete()` by to hlídaly v databázi, jenže těch
         * devět sloupců je nemá. Tahle obsluha platí na každém ovladači
         * a pokrývá i `forceDelete()`, kterým mažou obě obrazovky koše
         * i noční úklid.
         */
        static::deleting(function (MediaItem $m) {
            if (! $m->isForceDeleting()) {
                return;
            }

            foreach (self::ODKAZY as $tabulka => $sloupec) {
                if (! Tabulky::je($tabulka) || ! Tabulky::sloupec($tabulka, $sloupec)) {
                    continue;
                }

                DB::table($tabulka)->where($sloupec, $m->id)->update([$sloupec => null]);
            }
        });
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->whereNull('trashed_at')->where('is_hidden', false);
    }

    /**
     * Co se smí kopírovat do cizího cloudu (Disk, Dropbox, OneDrive, WebDAV).
     *
     * Ne koš a ne trezor — rozhodnutí 27. 9. 2026: „Fotky z trezoru nejdou na
     * cloud." Stejná podmínka jako `active`, ale pojmenovaná zvlášť: kdo ji
     * píše, má vědět, že tu jde o zálohu, ne o mřížku. Sloupce s tabulkou,
     * aby prošla i v dotazech s joinem (chytrá alba).
     */
    public function scopeSmiDoCloudu($query)
    {
        return $query->whereNull($query->qualifyColumn('trashed_at'))
            ->where($query->qualifyColumn('is_hidden'), false);
    }

    public function scopePhotos($query)
    {
        return $query->where('media_type', 'photo');
    }

    public function scopeVideos($query)
    {
        return $query->where('media_type', 'video');
    }

    public function scopeFavorites($query)
    {
        return $query->where('is_favorite', true);
    }

    public function scopeArchived($query)
    {
        return $query->where('is_archived', true);
    }

    public function scopeTrashed($query)
    {
        return $query->whereNotNull('trashed_at');
    }

    public function scopeNotTrashed($query)
    {
        return $query->whereNull('trashed_at');
    }

    /** Navržené ke smazání, ještě bez souhlasu druhého — pořád v knihovně. */
    public function scopeCekaNaSmazani($query)
    {
        return $query->whereNotNull('trash_requested_at')->whereNull('trashed_at');
    }

    public function scopeReady($query)
    {
        return $query->where('status', 'ready');
    }

    /**
     * Za jak dlouho se `uploading` bez pohybu bere jako zaseknuté.
     *
     * Každá odeslaná část souboru řádek osvěží (viz UploadDriveChunkJob), takže
     * i dlouhé video se sem nedostane, dokud se nahrává. Tohle je nahrávání,
     * kterému umřel pracovník nebo došly pokusy — to se znovu zkusit smí.
     */
    public const NAHRAVANI_NA_DISK_ZASEKNUTE_PO_HODINACH = 6;

    /**
     * Originály, které na Google Disku ještě nejsou a ani se tam právě nenahrávají.
     *
     * Disk si kopii nepamatuje variantou (`disk = google_drive`), ale sloupcem
     * `drive_file_id`. Rozběhnuté nahrávání se přeskočí, jinak by noční
     * dorovnání nebo „Zkusit znovu" založilo na Disku druhý soubor.
     */
    public function scopeBezKopieNaDisku($query)
    {
        return $query->whereNull('drive_file_id')
            ->where(fn ($q) => $q->whereNull('storage_status')
                ->orWhere('storage_status', '!=', 'uploading')
                ->orWhere('updated_at', '<', now()->subHours(self::NAHRAVANI_NA_DISK_ZASEKNUTE_PO_HODINACH)));
    }

    /** Nahrávání na Disk právě běží (a není zaseknuté). */
    public function nahravaNaDisk(): bool
    {
        return $this->storage_status === 'uploading'
            && $this->updated_at !== null
            && $this->updated_at->gt(now()->subHours(self::NAHRAVANI_NA_DISK_ZASEKNUTE_PO_HODINACH));
    }

    // Relations
    public function gallerySpace()
    {
        return $this->belongsTo(GallerySpace::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function primaryAlbum()
    {
        return $this->belongsTo(Album::class, 'primary_album_id');
    }

    public function albums()
    {
        return $this->belongsToMany(Album::class, 'album_media')
            ->withPivot(['sort_order', 'is_cover', 'added_at', 'added_by']);
    }

    public function variants()
    {
        return $this->hasMany(MediaVariant::class);
    }

    public function getVariant(string $type): ?MediaVariant
    {
        return $this->variants->firstWhere('type', $type);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        $variant = $this->getVariant('thumbnail') ?? $this->getVariant('small');

        return $variant ? $variant->url : null;
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'media_tag')
            ->withPivot(['tagged_by', 'created_at']);
    }

    public function people()
    {
        return $this->belongsToMany(Person::class, 'media_person')
            ->withPivot(['tagged_by', 'created_at']);
    }

    public function places()
    {
        return $this->belongsToMany(Place::class, 'media_place')
            ->withPivot('is_primary');
    }

    public function edits()
    {
        return $this->hasMany(MediaEdit::class);
    }

    public function currentEdit()
    {
        return $this->hasOne(MediaEdit::class)->where('is_current', true);
    }

    public function stacks()
    {
        return $this->belongsToMany(MediaStack::class, 'media_stack_items')
            ->withPivot(['sort_order', 'is_cover']);
    }

    public function userFavoritedBy()
    {
        // Viz User::favorites() — `user_favorites` nemá `updated_at`.
        return $this->belongsToMany(User::class, 'user_favorites')->withPivot('created_at');
    }

    public function userRatings()
    {
        return $this->belongsToMany(User::class, 'user_ratings')
            ->withPivot('rating')
            ->withTimestamps();
    }

    public function hasGps(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function isSoftTrashed(): bool
    {
        return $this->trashed_at !== null;
    }

    public function rebuildSearchText(): void
    {
        $this->updateQuietly(['search_text' => $this->hledanyText()]);
    }

    /** Měsíce v 1. a 2. pádě — „srpen" i „srpna" (fotky ze srpna). */
    private const MESICE = [
        1 => 'leden ledna', 'únor února', 'březen března', 'duben dubna', 'květen května', 'červen června',
        'červenec července', 'srpen srpna', 'září', 'říjen října', 'listopad listopadu', 'prosinec prosince',
    ];

    /** Roční doby i v 6. pádě, jak je člověk napíše: „v létě", „na jaře". */
    private const DOBY = [
        'zima zimě', 'zima zimě', 'jaro jaře', 'jaro jaře', 'jaro jaře', 'léto létě',
        'léto létě', 'léto létě', 'podzim podzimu', 'podzim podzimu', 'podzim podzimu', 'zima zimě',
    ];

    /**
     * Všechno, podle čeho jde fotku najít, jedním řetězcem pro `search_text`.
     *
     * Vazby se berou načtené, když jsou (`ObnovaHledani` je načte naráz
     * pro celou dávku); jinak se načtou tady. Na konci je táž věc ještě
     * jednou malými písmeny bez diakritiky (`FulltextDotaz::slozit()`), takže
     * jeden sloupec a jeden FULLTEXT index slouží MySQL i SQLite — a „lyse
     * hore" najde „Lysé hoře" i tam, kde `LIKE` diakritiku nesjednotí.
     *
     * Chyběla místa zapsaná v prototypu (`location_name`), alba mimo hlavní,
     * měsíc, rok, roční doba, druh a přípona souboru — „srpen 2025 video"
     * nenašlo nic, i když to na fotce bylo.
     */
    public function hledanyText(): string
    {
        $kdy = $this->taken_at;
        $alba = $this->albums->pluck('title');
        $cesta = $this->primaryAlbum?->full_display_path ?: $this->primaryAlbum?->title;

        $casti = array_filter([
            $this->original_filename,
            $this->display_title,
            $this->description,
            $this->caption,
            $this->notes,
            $this->location_name,
            $this->location_country,
            $this->camera_make,
            $this->camera_model,
            $this->lens_model,
            $cesta,
            $alba->reject(fn ($titul) => $cesta !== null && str_contains((string) $cesta, (string) $titul))->implode(' '),
            $this->tags->pluck('name')->implode(' '),
            // Skrytá osoba „zmizí z hledání" (viz `Knihovna::lideNaFotkach()`).
            $this->people->reject(fn ($osoba) => (bool) $osoba->is_hidden)->pluck('name')->implode(' '),
            $this->places->pluck('name')->implode(' '),
            $this->places->pluck('city')->filter()->implode(' '),
            $this->places->pluck('country')->filter()->implode(' '),
            $kdy ? self::MESICE[$kdy->month].' '.$kdy->year.' '.self::DOBY[$kdy->month - 1] : null,
            match ($this->media_type) {
                'video' => 'video',
                'photo' => 'fotka',
                default => null,
            },
            $this->extension,
        ], fn ($cast) => $cast !== null && trim((string) $cast) !== '');

        $text = preg_replace('/\s+/u', ' ', implode(' ', $casti)) ?? '';
        $slozeny = FulltextDotaz::slozit($text);

        return trim($slozeny === $text ? $text : $text.' '.$slozeny);
    }
}
