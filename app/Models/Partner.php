<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Partner — protistrana i vlastník peněz.
 *
 * Může to být člověk z aplikace, člověk mimo ni, firma nebo organizace. Proto `kind`
 * a nepovinná vazba na uživatele: dodavatel nemá důvod se sem přihlašovat, ale vystupuje
 * v transakcích stejně jako kdokoliv jiný.
 */
class Partner extends Model
{
    use SoftDeletes;
    use Concerns\BelongsToGallerySpace;

    public const KINDS = ['person', 'company', 'organization'];

    protected $fillable = [
        'uuid', 'gallery_space_id', 'kind', 'name', 'user_id',
        'registration_no', 'vat_no', 'email', 'note', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $p) => $p->uuid ??= (string) Str::uuid());
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function wallets()
    {
        return $this->hasMany(Wallet::class);
    }

    /** Jak se druhu partnera říká česky — do popisků, ať je poznat firma od člověka. */
    public function kindLabel(): string
    {
        return match ($this->kind) {
            'company' => 'firma',
            'organization' => 'organizace',
            default => 'osoba',
        };
    }
}
