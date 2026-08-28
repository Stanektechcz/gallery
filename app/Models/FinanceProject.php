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
        'budget_amount', 'reserve_amount', 'default_wallet_id', 'is_active', 'note',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'budget_amount' => 'decimal:2',
            'reserve_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Označí tuhle cestu jako aktivní a ostatní zhasne.
     *
     * Aktivních smí být jen jedna. Dvě by znamenaly, že se nový výdaj tiše přiřadí
     * k jednomu ze dvou pobytů podle pořadí v databázi — a nikdo by si toho nevšiml,
     * dokud by na konci nesouhlasily součty obou cest.
     */
    public function aktivuj(): void
    {
        static::withoutGlobalScopes()
            ->where('gallery_space_id', $this->gallery_space_id)
            ->where('id', '!=', $this->id)
            ->update(['is_active' => false]);

        $this->forceFill(['is_active' => true])->save();
    }

    /** Kolik dní z cesty ještě zbývá. Null u cesty bez konce. */
    public function dniDoKonce(?\Illuminate\Support\Carbon $dnes = null): ?int
    {
        if ($this->ends_on === null) return null;

        $dnes ??= \Illuminate\Support\Carbon::today();

        return max(0, (int) $dnes->diffInDays($this->ends_on, false));
    }

    public function defaultWallet(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'default_wallet_id');
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
