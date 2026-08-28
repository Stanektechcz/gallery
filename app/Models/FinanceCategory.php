<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGallerySpace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Kategorie výdaje nebo příjmu — vlastnictví prostoru, ne jednoho rozpočtu.
 *
 * Díky tomu jsou „Potraviny" v lednu a „Potraviny" v prosinci táž věc a dá se mezi
 * měsíci i cestami porovnávat. Limit na kategorii patří rozpočtu, ne sem: „Potraviny
 * 400 €" platí pro tenhle pobyt a další cesta ho nemá dědit.
 */
class FinanceCategory extends Model
{
    use BelongsToGallerySpace;
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'gallery_space_id', 'name', 'kind', 'icon', 'color',
        'is_favourite', 'is_active', 'sort_order',
        'default_wallet_id', 'default_split', 'default_split_value',
    ];

    /**
     * Výchozí sada podle sekce 19 zadání.
     *
     * Oblíbené jsou ty, které se na cestě zapisují nejčastěji — v rychlé volbě u
     * formuláře se jich vejde šest a ostatní jsou za tlačítkem „Všechny kategorie".
     * Barvy jsou ze schválené palety grafů, aby kategorie měla stejnou barvu v seznamu
     * i ve výsečovém grafu a nemusela se nikde přiřazovat podle pořadí.
     */
    public const VYCHOZI = [
        ['name' => 'Potraviny', 'icon' => 'shopping-cart', 'color' => 'var(--graf-1)', 'is_favourite' => true],
        ['name' => 'Restaurace a kavárny', 'icon' => 'utensils', 'color' => 'var(--graf-2)', 'is_favourite' => true],
        ['name' => 'Ubytování', 'icon' => 'bed', 'color' => 'var(--graf-3)', 'is_favourite' => true],
        ['name' => 'Doprava', 'icon' => 'bus', 'color' => 'var(--graf-4)', 'is_favourite' => true],
        ['name' => 'Benzín', 'icon' => 'fuel', 'color' => 'var(--graf-5)'],
        ['name' => 'Parkování a mýtné', 'icon' => 'circle-parking', 'color' => 'var(--graf-6)'],
        ['name' => 'Volný čas a výlety', 'icon' => 'ticket', 'color' => 'var(--graf-7)', 'is_favourite' => true],
        ['name' => 'Drogerie a domácnost', 'icon' => 'spray-can', 'color' => 'var(--graf-8)', 'is_favourite' => true],
        ['name' => 'Zdraví a lékárna', 'icon' => 'pill', 'color' => 'var(--graf-1)'],
        ['name' => 'Oblečení a nákupy', 'icon' => 'shirt', 'color' => 'var(--graf-2)'],
        ['name' => 'Psi', 'icon' => 'dog', 'color' => 'var(--graf-3)'],
        ['name' => 'Telefon a internet', 'icon' => 'smartphone', 'color' => 'var(--graf-4)'],
        ['name' => 'Směnné a bankovní poplatky', 'icon' => 'percent', 'color' => 'var(--graf-5)'],
        ['name' => 'Ostatní', 'icon' => 'circle-ellipsis', 'color' => 'var(--graf-6)'],
    ];

    /** Zdroje příjmu podle sekce 9. */
    public const VYCHOZI_PRIJMY = [
        ['name' => 'Mzda', 'icon' => 'banknote'],
        ['name' => 'Cestovní náhrada', 'icon' => 'plane'],
        ['name' => 'Vrácené peníze', 'icon' => 'undo-2'],
        ['name' => 'Příspěvek', 'icon' => 'hand-coins'],
        ['name' => 'Dar', 'icon' => 'gift'],
        ['name' => 'Ostatní příjem', 'icon' => 'circle-ellipsis'],
    ];

    protected function casts(): array
    {
        return [
            'is_favourite' => 'boolean',
            'is_active' => 'boolean',
            'default_split_value' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $k) => $k->uuid ??= (string) Str::uuid());
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'category_id');
    }

    /**
     * Založí výchozí sadu, pokud prostor ještě žádnou nemá.
     *
     * Prázdný seznam kategorií je nejhorší možný první dojem: formulář výdaje bez
     * kategorie nejde uložit, takže by první útratu předcházelo zakládání číselníku.
     */
    public static function nachystej(int $spaceId): void
    {
        if (static::withoutGlobalScopes()->where('gallery_space_id', $spaceId)->exists()) {
            return;
        }

        $poradi = 0;

        foreach (static::VYCHOZI as $k) {
            static::create($k + [
                'gallery_space_id' => $spaceId,
                'kind' => 'expense',
                'sort_order' => $poradi += 10,
            ]);
        }

        foreach (static::VYCHOZI_PRIJMY as $k) {
            static::create($k + [
                'gallery_space_id' => $spaceId,
                'kind' => 'income',
                'color' => 'var(--graf-3)',
                'sort_order' => $poradi += 10,
            ]);
        }
    }
}
