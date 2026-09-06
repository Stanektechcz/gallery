<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Návrh, na který se dá použít veto. */
class CoupleVetoProposal extends Model
{
    protected $fillable = [
        'uuid', 'client_id', 'gallery_space_id', 'proposed_by', 'text', 'price', 'proposed_on', 'outcome',
    ];

    protected function casts(): array
    {
        return ['proposed_on' => 'date'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function navrhl()
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }
}
