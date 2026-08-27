<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Projekt, domácnost, akce nebo zahraniční cesta.
 *
 * Jedna tabulka na všechno čtvero, protože z pohledu peněz je to totéž: něco, na co se
 * utrácí a co má odpovědného člověka, období a rozpočet. Rozlišuje je `kind`, aby šlo
 * cestu ukázat s poli, která má navíc — zemí, městem a účastníky.
 */
class FinanceProject extends Model
{
    use SoftDeletes;
    use Concerns\BelongsToGallerySpace;

    public const KINDS = ['project', 'household', 'event', 'trip'];

    /** Stavy podle zadání: od návrhu po archiv. */
    public const STATES = ['draft', 'pending', 'approved', 'active', 'closed', 'archived'];

    protected $fillable = [
        'uuid', 'gallery_space_id', 'kind', 'name', 'purpose',
        'country', 'city', 'starts_on', 'ends_on',
        'base_currency', 'responsible_partner_id', 'state',
    ];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $p) => $p->uuid ??= (string) Str::uuid());
    }

    public function responsible()
    {
        return $this->belongsTo(Partner::class, 'responsible_partner_id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function stateLabel(): string
    {
        return match ($this->state) {
            'draft' => 'návrh',
            'pending' => 'čeká na schválení',
            'approved' => 'schváleno',
            'active' => 'aktivní',
            'closed' => 'uzavřeno',
            'archived' => 'archivováno',
            default => $this->state,
        };
    }
}
