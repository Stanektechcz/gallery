<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGallerySpace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Předpis pravidelné platby — nájem, telefon, pojištění.
 *
 * Je to **vzor, ne transakce**. Sám o sobě zůstatek nemění; teprve zápis, který z něj
 * vznikne, jsou skutečné peníze. Díky tomu jde předpis založit dopředu na celý pobyt,
 * aniž by se hned odečetlo šest nájmů, které ještě neodešly.
 */
class FinanceRecurring extends Model
{
    use BelongsToGallerySpace;
    use SoftDeletes;

    protected $table = 'finance_recurring';

    protected $fillable = [
        'uuid', 'gallery_space_id', 'name', 'type', 'amount', 'currency',
        'wallet_id', 'finance_category_id', 'finance_project_id', 'payer_partner_id', 'split',
        'day_of_month', 'starts_on', 'ends_on', 'generated_until', 'is_active', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'generated_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $p) => $p->uuid ??= (string) Str::uuid());
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(FinanceProject::class, 'finance_project_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'payer_partner_id');
    }

    /**
     * Data splátek v zadaném rozsahu.
     *
     * Den v měsíci se ořízne na délku měsíce: předpis „31." v únoru vyjde na
     * osmadvacátého, ne na třetího března. Posunout ho dopředu by znamenalo, že se
     * únorová splátka objeví v březnu a únor bude vypadat jako měsíc bez nájmu.
     *
     * @return array<int, Carbon>
     */
    public function terminy(Carbon $od, Carbon $do): array
    {
        $zacatek = $od->copy()->max($this->starts_on);
        $konec = $this->ends_on ? $do->copy()->min($this->ends_on) : $do->copy();

        if ($zacatek->greaterThan($konec)) {
            return [];
        }

        $terminy = [];
        $mesic = $zacatek->copy()->startOfMonth();

        while ($mesic->lessThanOrEqualTo($konec)) {
            $den = $mesic->copy()->day(min($this->day_of_month, $mesic->daysInMonth));

            if ($den->betweenIncluded($zacatek, $konec)) {
                $terminy[] = $den;
            }

            $mesic->addMonthNoOverflow();
        }

        return $terminy;
    }

    /**
     * Kolik z předpisu ještě přijde do konce daného období.
     *
     * Tohle je číslo, kvůli kterému předpisy existují: peníze, které už mají majitele,
     * i když ještě leží na účtu.
     */
    public function zbyvaDoKonce(Carbon $dnes, ?Carbon $konec): float
    {
        if (! $this->is_active || $konec === null) {
            return 0.0;
        }

        // Od zítřka — dnešní splátka buď už odešla a je zapsaná, nebo se zapíše dnes.
        return count($this->terminy($dnes->copy()->addDay(), $konec)) * (float) $this->amount;
    }
}
