<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Žádost mezi partnery — „vezmeš cestou chleba?".
 *
 * Žádost není úkol: nezakládá se na nástěnce a nepřipomíná se každý den.
 * Kolikrát se přesto připomenout musela, drží záznamy vedle — a z nich se
 * počítá přehled trpělivosti.
 */
class CoupleNudge extends Model
{
    protected $fillable = [
        'uuid', 'client_id', 'gallery_space_id', 'asked_by', 'asked_of',
        'text', 'kind', 'state', 'note', 'automated_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return ['automated_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $z) => $z->uuid ??= (string) Str::uuid());
    }

    public function pripominky()
    {
        return $this->hasMany(CoupleNudgeReminder::class);
    }

    public function odKoho()
    {
        return $this->belongsTo(User::class, 'asked_by');
    }

    public function komu()
    {
        return $this->belongsTo(User::class, 'asked_of');
    }
}
