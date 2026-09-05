<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Přihlašovací klíč z Touch ID / Face ID / čtečky v displeji.
 * Jeden uživatel může mít víc zařízení — každé má vlastní klíč.
 */
class WebauthnCredential extends Model
{
    protected $fillable = [
        'user_id',
        'credential_id',
        'public_key',
        'sign_count',
        'transports',
        'aaguid',
        'label',
        'last_used_at',
    ];

    protected $casts = [
        'transports' => 'array',
        'sign_count' => 'integer',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Klíče jednoho uživatele — pro allowCredentials v /login/options. */
    public static function idsForUser(int $userId): array
    {
        return static::query()->where('user_id', $userId)->pluck('credential_id')->all();
    }
}
