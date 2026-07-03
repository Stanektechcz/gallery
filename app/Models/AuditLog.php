<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'action', 'subject_type', 'subject_id',
        'payload', 'ip_address', 'user_agent', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user() { return $this->belongsTo(User::class); }

    public static function record(string $action, mixed $subject = null, array $payload = []): void
    {
        static::create([
            'user_id'      => auth()->id(),
            'action'       => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id'   => $subject?->id,
            'payload'      => $payload ?: null,
            'ip_address'   => request()->ip(),
            'user_agent'   => substr(request()->userAgent() ?? '', 0, 512),
            'created_at'   => now(),
        ]);
    }
}
