<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Slib, který někdo někomu vyslovil.
 *
 * Není to úkolník: sem patří jen to, co zaznělo. Proto `said` — kde to padlo —
 * a proto se zrušené po dohodě vede zvlášť od nedodrženého. Ten rozdíl je celý
 * smysl téhle sekce.
 */
class CouplePromise extends Model
{
    protected $fillable = [
        'uuid', 'client_id', 'gallery_space_id', 'promised_by', 'promised_to',
        'what', 'due_label', 'due_on', 'state', 'said', 'settled_at',
    ];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'settled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $s) => $s->uuid ??= (string) Str::uuid());
    }

    public function slibil()
    {
        return $this->belongsTo(User::class, 'promised_by');
    }

    public function komu()
    {
        return $this->belongsTo(User::class, 'promised_to');
    }
}
