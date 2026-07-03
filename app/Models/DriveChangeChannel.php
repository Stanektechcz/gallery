<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriveChangeChannel extends Model
{
    protected $fillable = [
        'storage_connection_id', 'channel_id', 'resource_id', 'channel_token',
        'page_token', 'expires_at', 'is_active', 'renewed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'  => 'datetime',
            'renewed_at'  => 'datetime',
            'is_active'   => 'boolean',
        ];
    }

    public function storageConnection() { return $this->belongsTo(StorageConnection::class); }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isExpiringSoon(): bool
    {
        return $this->expires_at && $this->expires_at->diffInHours(now()) < 12;
    }
}
