<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriveChange extends Model
{
    protected $table = 'drive_changes';
    protected $fillable = [
        'storage_connection_id',
        'change_type',
        'file_id',
        'file_name',
        'removed',
        'trashed',
        'change_payload',
        'processed_status',
        'change_time',
    ];

    protected function casts(): array
    {
        return [
            'removed'        => 'boolean',
            'trashed'        => 'boolean',
            'change_payload' => 'array',
            'change_time'    => 'datetime',
        ];
    }

    public function storageConnection()
    {
        return $this->belongsTo(StorageConnection::class);
    }
}
