<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Předvolby modulu — jak se má rozpočet chovat, než mu kdo cokoli řekne.
 *
 * Tabulka existovala od začátku a nepoužívalo ji nic: dvacet sloupců, které se nikde
 * nečetly ani nezapisovaly. Uživatel si tak nemohl nastavit vůbec nic a modul se
 * pokaždé otevřel stejně, ať s tím kdo pracoval jakkoli.
 *
 * Vystavuje se **jen to, co něco doopravdy dělá.** Přepínač, po kterém se nic
 * nestane, je horší než chybějící přepínač: jednou se přepne, nic se nezmění a od
 * té chvíle nikdo nevěří ani ostatním.
 *
 * Předvolby jsou na prostor, ne na člověka. Adri a Maki vedou jednu knihu a dvě
 * různá výchozí období by znamenala, že si každý pod stejným číslem představí jiný
 * kus času.
 */
class FinanceSettings extends Model
{
    protected $table = 'finance_settings';

    /** Co je napojené a co tedy smí do API. Zbytek sloupců zůstává nepoužitý. */
    public const VYSTAVENE = [
        'home_currency', 'travel_currency', 'default_period', 'default_tab',
        'list_density', 'default_reserve', 'alert_thresholds', 'show_partner_balance',
    ];

    protected $fillable = [
        'gallery_space_id', 'name',
        ...self::VYSTAVENE,
    ];

    protected function casts(): array
    {
        return [
            'default_reserve' => 'decimal:2',
            'show_partner_balance' => 'boolean',
        ];
    }

    /**
     * Předvolby prostoru, v případě potřeby rovnou založené.
     *
     * Výchozí hodnoty odpovídají tomu, jak se modul choval dosud, takže nasazení nic
     * nikomu nepřevrátí pod rukama.
     */
    public static function proProstor(int $spaceId): self
    {
        return static::firstOrCreate(
            ['gallery_space_id' => $spaceId],
            [
                'name' => 'Výchozí',
                'home_currency' => 'CZK',
                'travel_currency' => 'EUR',
                'default_period' => 'mesic',
                'default_tab' => 'prehled',
                'list_density' => 'pohodlne',
                'default_reserve' => 0,
                'alert_thresholds' => '80,90,100',
                'show_partner_balance' => true,
            ],
        );
    }

    /** @return array<string, mixed> */
    public function proObrazovku(): array
    {
        return [
            'home_currency' => $this->home_currency ?? 'CZK',
            'travel_currency' => $this->travel_currency ?? 'EUR',
            'default_period' => $this->default_period ?? 'mesic',
            'default_tab' => $this->default_tab ?? 'prehled',
            'list_density' => $this->list_density ?? 'pohodlne',
            'default_reserve' => (float) ($this->default_reserve ?? 0),
            'alert_thresholds' => $this->alert_thresholds ?? '80,90,100',
            'show_partner_balance' => (bool) ($this->show_partner_balance ?? true),
        ];
    }
}
