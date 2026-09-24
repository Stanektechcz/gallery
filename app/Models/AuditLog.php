<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'gallery_space_id', 'action', 'subject_type', 'subject_id',
        'payload', 'ip_address', 'user_agent', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $action, mixed $subject = null, array $payload = []): void
    {
        static::create([
            'user_id' => auth()->id(),
            // Galerie, ve které se to stalo. Bere se z předmětu akce —
            // ten ji nese; `GallerySpace` sám je svým vlastním prostorem.
            'gallery_space_id' => static::prostorPredmetu($subject),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->id,
            'payload' => $payload ?: null,
            'ip_address' => request()->ip(),
            'user_agent' => substr(request()->userAgent() ?? '', 0, 512),
            'created_at' => now(),
        ]);
    }

    /**
     * Do které galerie záznam patří.
     *
     * Nejdřív podle předmětu akce — ten galerii nese a `GallerySpace` sám je
     * svým vlastním prostorem. Když ji předmět nemá, vezme se galerie toho,
     * kdo akci vyvolal.
     *
     * Ta druhá cesta tu dřív nebyla a stálo to polovinu protokolu: záznamy
     * bez předmětu (`app_lock.*`, `vault.*`, `auth.login*`) i ty, jejichž
     * předmětem je `User` — který sloupec `gallery_space_id` nemá — se
     * ukládaly s `null`. Panel Aktivita čte `where('gallery_space_id', …)`,
     * takže se odemykání zámku, otevření trezoru ani přihlášení dvojici
     * nikdy neukázalo, přestože jim to obrazovka zámku i trezoru slibuje.
     *
     * Bez přihlášeného člověka zůstává `null` — třeba u úloh z fronty,
     * kde se galerie odvodit nedá a hádat se nemá.
     */
    private static function prostorPredmetu(mixed $subject): ?int
    {
        if ($subject instanceof GallerySpace) {
            return (int) $subject->id;
        }

        $id = is_object($subject) ? ($subject->gallery_space_id ?? null) : null;

        return $id === null ? static::prostorPrihlaseneho() : (int) $id;
    }

    /**
     * Galerie toho, kdo akci vyvolal.
     *
     * Stejné pořadí jako `UrcujePar::parId()`: výchozí prostor, jinak
     * nejstarší. Bez pevného řazení by účet ve dvou galeriích zapisoval
     * pokaždé jinam.
     */
    private static function prostorPrihlaseneho(): ?int
    {
        $prostor = auth()->user()?->gallerySpaces()
            ->orderByDesc('gallery_spaces.is_default')
            ->orderBy('gallery_spaces.id')
            ->first();

        return $prostor === null ? null : (int) $prostor->id;
    }
}
