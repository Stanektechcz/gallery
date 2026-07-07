<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemoryInteraction extends Model
{
    protected $fillable = [
        'user_id', 'fingerprint', 'memory_type', 'action', 'snoozed_until', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'snoozed_until' => 'datetime',
            'metadata' => 'array',
        ];
    }
}

