<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Crypt;

class StorageConnection extends Model
{
    protected $fillable = [
        'provider', 'owner_user_id', 'google_subject_id', 'account_email',
        'encrypted_access_token', 'encrypted_refresh_token', 'token_expires_at',
        'granted_scopes_json', 'connection_status', 'last_successful_request_at',
        'last_error_at', 'last_error_code', 'last_error_message',
        'root_folder_id', 'root_folder_name', 'scope_mode',
        'quota_total', 'quota_used', 'quota_refreshed_at',
        'connected_at', 'revoked_at',
    ];

    protected $hidden = ['encrypted_access_token', 'encrypted_refresh_token'];

    protected function casts(): array
    {
        return [
            'token_expires_at'            => 'datetime',
            'last_successful_request_at'  => 'datetime',
            'last_error_at'               => 'datetime',
            'quota_refreshed_at'          => 'datetime',
            'connected_at'                => 'datetime',
            'revoked_at'                  => 'datetime',
            'granted_scopes_json'         => 'array',
        ];
    }

    // Encrypted token accessors (never expose raw)
    public function getAccessToken(): ?string
    {
        return $this->encrypted_access_token
            ? Crypt::decryptString($this->encrypted_access_token)
            : null;
    }

    public function setAccessToken(string $token): void
    {
        $this->encrypted_access_token = Crypt::encryptString($token);
    }

    public function getRefreshToken(): ?string
    {
        return $this->encrypted_refresh_token
            ? Crypt::decryptString($this->encrypted_refresh_token)
            : null;
    }

    public function setRefreshToken(string $token): void
    {
        $this->encrypted_refresh_token = Crypt::encryptString($token);
    }

    public function isHealthy(): bool       { return $this->connection_status === 'healthy'; }
    public function isDisconnected(): bool  { return $this->connection_status === 'disconnected'; }
    public function needsRefresh(): bool    { return $this->connection_status === 'refresh_required'; }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }

    public function owner() { return $this->belongsTo(User::class, 'owner_user_id'); }

    public function operations()
    {
        return $this->hasMany(StorageOperation::class);
    }

    public function changeChannels()
    {
        return $this->hasMany(DriveChangeChannel::class);
    }

    public function markHealthy(): void
    {
        $this->update([
            'connection_status'           => 'healthy',
            'last_successful_request_at'  => now(),
            'last_error_at'               => null,
            'last_error_code'             => null,
            'last_error_message'          => null,
        ]);
    }

    public function markError(string $code, string $message): void
    {
        $this->update([
            'last_error_at'      => now(),
            'last_error_code'    => $code,
            'last_error_message' => $message,
        ]);
    }

    public function markStatus(string $status): void
    {
        $this->update(['connection_status' => $status]);
    }
}
