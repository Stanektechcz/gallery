<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGallerySpace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Šablona rychlého zápisu — „Potraviny, EUR karta, Maki, společné 50/50".
 *
 * Předvyplňuje všechno kromě částky. Ta se nepředvyplňuje nikdy: je to jediný údaj,
 * který se pokaždé liší, a nabídnout u něj číslo znamená, že ho někdo jednou
 * přehlédne a uloží útratu, která se nestala.
 */
class FinanceTemplate extends Model
{
    use BelongsToGallerySpace;

    protected $fillable = [
        'uuid', 'gallery_space_id', 'name', 'type',
        'finance_category_id', 'wallet_id', 'payer_partner_id', 'split',
        'sort_order', 'used_count',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $s) => $s->uuid ??= (string) Str::uuid());
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'payer_partner_id');
    }
}
