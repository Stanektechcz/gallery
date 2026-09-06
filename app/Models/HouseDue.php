<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Lhůta, platba nebo předplatné.
 *
 * `delay_note` a `delay_cost` nejsou ozdoba: lhůta bez ceny odkladu je jen
 * další řádek v seznamu, který se dá odsunout. S ní se dá rozhodnout.
 */
class HouseDue extends Model
{
    protected $fillable = [
        'uuid', 'client_id', 'gallery_space_id', 'what', 'kind', 'due_on',
        'amount', 'user_id', 'note', 'delay_note', 'delay_cost', 'change_note', 'settled_at',
    ];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'settled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $zavazek) => $zavazek->uuid ??= (string) Str::uuid());
    }

    public function kdo()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
