<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Jeden bod protokolu nesouhlasu.
 *
 * Rozdíl mezi podmínkou a přáním je celý smysl té obrazovky: podmínek se nedá
 * mít pět a přání se nedá vetovat. Autor je člověk, ne strana „moje/jejich" —
 * druhý z dvojice vidí totéž z opačné strany.
 */
class CoupleDisagreementPoint extends Model
{
    protected $fillable = [
        'uuid', 'client_id', 'gallery_space_id', 'author_user_id', 'topic', 'text', 'tag', 'kind',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function autor()
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
