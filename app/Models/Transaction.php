<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Jeden záznam v účetní knize.
 *
 * Dvě strany — odkud a kam — a typ, který určuje, které se vyplní. Z toho plyne to
 * hlavní: **do příjmů a výdajů se počítají jen typy `income` a `expense`.** Převod,
 * směna, výběr ani vklad hospodářský výsledek nemění, jen přesouvají zůstatky.
 *
 * Výběr pěti set eur z banky není výdaj — peníze nikam nezmizely, jen se přesunuly do
 * hotovostní peněženky, a výdaj vznikne teprve jejich utracením. U směny je skutečným
 * nákladem jen poplatek. Kdyby se obojí počítalo jako běžná transakce, součet výdajů by
 * se nafoukl o částky, které nikdo neutratil — a nikdo by to nenašel, protože by to
 * „sedělo".
 */
class Transaction extends Model
{
    use SoftDeletes;
    use Concerns\BelongsToGallerySpace;

    /** Typy, které mění hospodářský výsledek. */
    public const VYSLEDKOVE = ['income', 'expense'];

    /** Typy, které jen přesouvají peníze mezi vlastními peněženkami. */
    public const PRESUNY = ['transfer', 'exchange', 'withdrawal', 'deposit'];

    public const TYPES = [...self::VYSLEDKOVE, ...self::PRESUNY];

    protected $fillable = [
        'uuid', 'client_key', 'gallery_space_id', 'type', 'occurred_at', 'booked_on',
        'wallet_from_id', 'wallet_to_id',
        'amount_from', 'currency_from', 'amount_to', 'currency_to',
        'rate', 'reference_rate', 'rate_source', 'fee_amount', 'fee_currency',
        'finance_project_id', 'category_id', 'payer_partner_id', 'beneficiary_partner_id',
        'counterparty', 'payment_method', 'description', 'receipt_media_id',
        'state', 'created_by', 'approved_by', 'approved_at',
        'fee_included', 'provider', 'place',
        'excluded_from_budget', 'exclusion_reason', 'refund_of_id', 'is_settlement',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'date',
            'booked_on' => 'date',
            'approved_at' => 'datetime',
            'amount_from' => 'decimal:2',
            'amount_to' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'rate' => 'decimal:8',
            'reference_rate' => 'decimal:8',
            'fee_included' => 'boolean',
            'excluded_from_budget' => 'boolean',
            'is_settlement' => 'boolean',
        ];
    }

    /**
     * Počítá se tenhle záznam do čerpání rozpočtu?
     *
     * Do rozpočtu jde jen spotřeba. Převod, směna ani vklad spotřebou nejsou — u směny
     * je nákladem jen poplatek, a ten se do rozpočtu přičítá zvlášť, ne přes typ.
     * Vyrovnání mezi partnery je převod, který mění saldo a nic neutrácí. A výdaj
     * ručně vyřazený z rozpočtu se sem nepočítá, i když spotřebou je — proto k němu
     * patří povinný důvod, aby šlo za půl roku zjistit proč.
     */
    public function countsTowardsBudget(): bool
    {
        return $this->type === 'expense'
            && ! $this->excluded_from_budget
            && ! $this->is_settlement;
    }

    /**
     * Skutečný poplatek v korunách nebo eurech, které opravdu odešly navíc.
     *
     * Poplatek zahrnutý v částce je už odečtený ze zůstatku — přičíst ho ještě jednou
     * do výdajů by ho zaplatilo dvakrát.
     */
    public function feePaidExtra(): float
    {
        return $this->fee_included ? 0.0 : (float) $this->fee_amount;
    }

    public function category()
    {
        return $this->belongsTo(FinanceCategory::class, 'category_id');
    }

    public function refundOf()
    {
        return $this->belongsTo(self::class, 'refund_of_id');
    }

    public function refunds()
    {
        return $this->hasMany(self::class, 'refund_of_id');
    }

    protected static function booted(): void
    {
        static::creating(fn (self $t) => $t->uuid ??= (string) Str::uuid());
    }

    public function walletFrom()
    {
        return $this->belongsTo(Wallet::class, 'wallet_from_id');
    }

    public function walletTo()
    {
        return $this->belongsTo(Wallet::class, 'wallet_to_id');
    }

    public function project()
    {
        return $this->belongsTo(FinanceProject::class, 'finance_project_id');
    }

    public function payer()
    {
        return $this->belongsTo(Partner::class, 'payer_partner_id');
    }

    public function beneficiary()
    {
        return $this->belongsTo(Partner::class, 'beneficiary_partner_id');
    }

    public function shares()
    {
        return $this->hasMany(TransactionShare::class);
    }

    public function receipt()
    {
        return $this->belongsTo(MediaItem::class, 'receipt_media_id');
    }

    /** Mění tahle transakce výsledek, nebo jen přesouvá peníze? */
    public function affectsResult(): bool
    {
        return in_array($this->type, self::VYSLEDKOVE, true);
    }

    /**
     * Kolik z téhle transakce je skutečný náklad.
     *
     * U výdaje celá částka. U směny a převodu jen poplatek — zbytek se jen přesunul.
     * U příjmu nic, ale poplatek může být i tam (příchozí platba ze zahraničí).
     */
    public function realCost(): float
    {
        if ($this->type === 'expense') {
            return (float) $this->amount_from + (float) $this->fee_amount;
        }

        return (float) $this->fee_amount;
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'income' => 'příjem',
            'expense' => 'výdaj',
            'transfer' => 'převod',
            'exchange' => 'směna',
            'withdrawal' => 'výběr hotovosti',
            'deposit' => 'vklad hotovosti',
            default => $this->type,
        };
    }
}
