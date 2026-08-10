<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AutomationRule extends Model
{
    protected $fillable = [
        'uuid', 'gallery_space_id', 'created_by', 'name', 'trigger',
        'conditions', 'action', 'action_config', 'is_enabled', 'last_run_at', 'run_count',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'action_config' => 'array',
            'is_enabled' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $rule): void {
            $rule->uuid ??= (string) Str::uuid();
        });
    }

    public function space() { return $this->belongsTo(GallerySpace::class, 'gallery_space_id'); }
    public function author() { return $this->belongsTo(User::class, 'created_by'); }
}
