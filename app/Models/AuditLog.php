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
     * Do které galerie předmět akce patří.
     *
     * Bez prostoru se záznam uloží dál (`null`) — deník akcí je i o věcech,
     * které k žádné galerii nepatří, třeba pozvánka do administrace.
     */
    private static function prostorPredmetu(mixed $subject): ?int
    {
        if ($subject instanceof GallerySpace) {
            return (int) $subject->id;
        }

        $id = is_object($subject) ? ($subject->gallery_space_id ?? null) : null;

        return $id === null ? null : (int) $id;
    }
}
