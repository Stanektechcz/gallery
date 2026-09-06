<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Záznam o odvedené práci.
 *
 * Jméno práce se opisuje, ne jen odkazuje: historie dělby má přežít i to, že
 * někdo tu práci ze seznamu smaže.
 */
class HouseChoreLogEntry extends Model
{
    protected $table = 'house_chore_log';

    protected $fillable = [
        'uuid', 'client_id', 'gallery_space_id', 'house_chore_id',
        'chore_name', 'user_id', 'minutes', 'done_at',
    ];

    protected function casts(): array
    {
        return ['done_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $zaznam) => $zaznam->uuid ??= (string) Str::uuid());
    }

    public function kdo()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
