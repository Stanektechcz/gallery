<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Nastavení kalendáře — hlavně komu se co ukazuje.
 */
class CycleSetting extends Model
{
    public const SHARE_NONE = 'none';
    public const SHARE_DATES = 'dates';
    public const SHARE_FULL = 'full';

    protected $fillable = [
        'user_id', 'gallery_space_id', 'share_level',
        'average_cycle_days', 'average_period_days',
        'remind_upcoming', 'remind_days_before', 'track_symptoms',
    ];

    protected function casts(): array
    {
        return [
            'remind_upcoming' => 'boolean',
            'track_symptoms' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Co smí vidět někdo jiný než majitelka. */
    public function allowsPartner(): bool
    {
        return $this->share_level !== self::SHARE_NONE;
    }

    public function allowsDetail(): bool
    {
        return $this->share_level === self::SHARE_FULL;
    }
}
