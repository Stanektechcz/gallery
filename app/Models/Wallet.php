<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Peněženka — jakékoliv místo, kde leží peníze v jedné měně.
 *
 * Bankovní účet, hotovost v kapse, karta. Jedna měna na peněženku schválně: účet, na
 * kterém leží koruny i eura zároveň, jsou ve skutečnosti dva účty, a míchat je znamená,
 * že se zůstatek nedá spočítat bez kurzu — tedy bez hádání.
 */
class Wallet extends Model
{
    use SoftDeletes;
    use Concerns\BelongsToGallerySpace;

    public const KINDS = ['bank', 'cash', 'card', 'other'];

    protected $fillable = [
        'uuid', 'gallery_space_id', 'partner_id', 'name', 'kind',
        'currency', 'opening_balance', 'iban', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['opening_balance' => 'decimal:2', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $w) => $w->uuid ??= (string) Str::uuid());
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    /** Transakce, které z peněženky odcházejí. */
    public function outgoing()
    {
        return $this->hasMany(Transaction::class, 'wallet_from_id');
    }

    /** Transakce, které do peněženky přicházejí. */
    public function incoming()
    {
        return $this->hasMany(Transaction::class, 'wallet_to_id');
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            'cash' => 'hotovost',
            'card' => 'karta',
            'other' => 'jiné',
            default => 'bankovní účet',
        };
    }
}
