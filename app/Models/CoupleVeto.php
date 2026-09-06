<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Použité veto.
 *
 * Datum je datum, ne popisek: veto se vrací po dvanácti měsících a bez něj by
 * se nedalo spočítat, kolik jich komu zbývá.
 */
class CoupleVeto extends Model
{
    // Laravel by z „veto" udělalo „vetos"; česky i anglicky je to „vetoes".
    protected $table = 'couple_vetoes';

    protected $fillable = [
        'uuid', 'client_id', 'gallery_space_id', 'couple_veto_proposal_id',
        'user_id', 'text', 'used_on', 'reason',
    ];

    protected function casts(): array
    {
        return ['used_on' => 'date'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function kdo()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
