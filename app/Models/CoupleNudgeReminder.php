<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Jedna odeslaná připomínka.
 *
 * Záznam s časem, ne čítač: obrazovka trpělivosti mluví o posledním měsíci
 * a z čísla se měsíc vyčíst nedá.
 */
class CoupleNudgeReminder extends Model
{
    public $timestamps = false;

    protected $fillable = ['couple_nudge_id', 'reminded_by', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function zadost()
    {
        return $this->belongsTo(CoupleNudge::class, 'couple_nudge_id');
    }
}
