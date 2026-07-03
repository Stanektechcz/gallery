<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StorageOperation extends Model
{
    protected $fillable = [
        'uuid', 'storage_connection_id', 'operation_type', 'entity_type', 'entity_id',
        'remote_file_id', 'idempotency_key', 'status', 'attempts', 'next_retry_at',
        'request_summary_json', 'response_summary_json', 'error_code', 'error_message',
        'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'next_retry_at'        => 'datetime',
            'started_at'           => 'datetime',
            'completed_at'         => 'datetime',
            'request_summary_json' => 'array',
            'response_summary_json'=> 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (StorageOperation $op) => $op->uuid ??= (string) Str::uuid());
    }

    public function storageConnection() { return $this->belongsTo(StorageConnection::class); }
}
