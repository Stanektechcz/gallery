<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UploadChunk extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'upload_session_id', 'chunk_index', 'path', 'size_bytes',
        'checksum', 'status', 'received_at',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function uploadSession() { return $this->belongsTo(UploadSession::class); }
}
