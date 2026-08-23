<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Žádost o peníze mezi partnery.
 *
 * Vyřízená částka a kurz se zapisují až při vyřízení: kolik doopravdy dorazilo ví jenom
 * ten, kdo směnu provedl, a odhadovat to dopředu znamená lhát si do rozpočtu.
 */
class MoneyRequest extends Model
{
    use \App\Models\Concerns\BelongsToGallerySpace;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid', 'gallery_space_id', 'from_user_id', 'to_user_id',
        'amount', 'currency', 'settled_amount', 'settled_currency', 'exchange_rate',
        'reason', 'status', 'responded_at', 'response_note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'settled_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'responded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $request) => $request->uuid ??= (string) Str::uuid());
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
