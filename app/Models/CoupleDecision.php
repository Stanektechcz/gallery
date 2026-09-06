<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Jedno rozhodnutí dvojice — a hlavně proč.
 *
 * Za rok se nikdo neptá, co jste rozhodli, ale proč a co tehdy bylo na stole.
 * Původní znění se nepřepisuje; změna zakládá revizi.
 */
class CoupleDecision extends Model
{
    protected $fillable = [
        'uuid', 'client_id', 'gallery_space_id', 'title', 'decided_on', 'together', 'decided_by',
        'status', 'why', 'rejected', 'review_note', 'review_on',
        'arbiter_user_id', 'arbiter_method', 'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_on' => 'date',
            'review_on' => 'date',
            'changed_at' => 'datetime',
            'together' => 'boolean',
            'why' => 'array',
            'rejected' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function revize()
    {
        return $this->hasMany(CoupleDecisionRevision::class)->orderBy('valid_from');
    }

    public function arbitr()
    {
        return $this->belongsTo(User::class, 'arbiter_user_id');
    }

    public function rozhodl()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
