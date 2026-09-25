<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

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
        'personal_access_token_id',
    ];

    protected $casts = [
        'transports' => 'array',
        'sign_count' => 'integer',
        'last_used_at' => 'datetime',
        'personal_access_token_id' => 'integer',
    ];

    /**
     * Zrušit otisky všech zařízení kromě toho, které drží token `$ponechatToken`.
     *
     * Otisk vydává nový token sám, takže odhlášení, které otisky nechá, nikoho
     * neodhlásí — stačí se otiskem přihlásit znovu. Otisk bez vazby na token
     * (starší záznam, nebo registrace ze sezení) se ruší taky: nikdo neví, čí je.
     * Bez `$ponechatToken` se ruší všechny.
     */
    public static function zrusKromeTokenu(User $user, ?int $ponechatToken = null): int
    {
        return static::query()
            ->where('user_id', $user->id)
            ->when($ponechatToken !== null, fn ($q) => $q->where(
                fn ($q) => $q->whereNull('personal_access_token_id')
                    ->orWhere('personal_access_token_id', '!=', $ponechatToken),
            ))
            ->delete();
    }

    /** Id tokenu, kterým se požadavek přihlásil — u sezení v prohlížeči `null`. */
    public static function tokenPozadavku(?User $user): ?int
    {
        $token = $user?->currentAccessToken();

        // Předek ze Sanctumu, ne vlastní model: `TransientToken` sezení id nemá.
        return $token instanceof SanctumToken && $token->exists ? (int) $token->getKey() : null;
    }

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
