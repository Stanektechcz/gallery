<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One person's change to one navigation entry.
 *
 * A row exists only where somebody moved, renamed, hid or nested something — see the
 * migration for why the arrangement is a difference rather than a copy.
 */
class UserNavigationItem extends Model
{
    protected $fillable = [
        'uuid', 'user_id', 'href', 'label', 'description', 'icon',
        'parent_id', 'position', 'is_hidden', 'is_group',
    ];

    protected function casts(): array
    {
        return ['is_hidden' => 'boolean', 'is_group' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->uuid ??= (string) Str::uuid());
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }
}
