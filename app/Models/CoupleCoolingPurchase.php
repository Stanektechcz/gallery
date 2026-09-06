<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Nákup, který čeká na rozvahu.
 *
 * `cools_until` je okamžik, ne počet dní: „zbývá 31 hodin" platí jen ve vteřinu,
 * kdy se to čte, a po zavření prohlížeče by lhůta zamrzla.
 */
class CoupleCoolingPurchase extends Model
{
    protected $fillable = [
        'uuid', 'client_id', 'gallery_space_id', 'what', 'price', 'requested_by',
        'opened_at', 'cools_until', 'opinion', 'opinion_by', 'verdict', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'cools_until' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function zada()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
