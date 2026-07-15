<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BankCategoryRule extends Model
{
    protected $fillable = ['uuid', 'gallery_space_id', 'created_by', 'field', 'operator', 'pattern', 'category', 'trip_action', 'priority', 'is_enabled'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $item) => $item->uuid ??= (string) Str::uuid());
    }
}
