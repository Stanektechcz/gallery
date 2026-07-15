<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BankConnection extends Model
{
    protected $fillable = ['uuid', 'gallery_space_id', 'connected_by', 'provider', 'institution_id', 'institution_name',
        'requisition_id', 'agreement_id', 'oauth_state_hash', 'status', 'sync_enabled', 'consent_expires_at',
        'last_synced_at', 'last_success_at', 'last_error', 'encrypted_metadata', 'revoked_at'];

    protected function casts(): array
    {
        return ['sync_enabled' => 'boolean', 'consent_expires_at' => 'datetime', 'last_synced_at' => 'datetime',
            'last_success_at' => 'datetime', 'revoked_at' => 'datetime', 'encrypted_metadata' => 'encrypted:array'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $item) => $item->uuid ??= (string) Str::uuid());
    }

    public function space()
    {
        return $this->belongsTo(GallerySpace::class, 'gallery_space_id');
    }

    public function accounts()
    {
        return $this->hasMany(BankAccount::class);
    }
}
