<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemoryPreference extends Model
{
    protected $fillable = [
        'user_id', 'frequency', 'enabled_types', 'hidden_person_ids',
        'hidden_place_ids', 'hidden_date_ranges', 'include_archived',
    ];

    protected function casts(): array
    {
        return [
            'enabled_types' => 'array',
            'hidden_person_ids' => 'array',
            'hidden_place_ids' => 'array',
            'hidden_date_ranges' => 'array',
            'include_archived' => 'boolean',
        ];
    }
}

