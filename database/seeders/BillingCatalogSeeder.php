<?php

namespace Database\Seeders;

use App\Models\BillingModule;
use App\Models\BillingPlan;
use Illuminate\Database\Seeder;

/**
 * The catalogue behind the pricing page. Prices are in minor units (haléře) so no
 * rounding creeps in. Safe to re-run: everything is keyed by code.
 */
class BillingCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'duo', 'name' => 'Duo', 'tagline' => 'Pro dva, kteří si chtějí pamatovat všechno',
                'description' => 'Galerie, alba, kalendář, cesty, kuchařka a společné plánování bez omezení počtu vzpomínek.',
                'price_monthly' => 0, 'member_limit' => 2, 'storage_limit_mb' => 25_000,
                'features' => ['Sdílená galerie a alba', 'Kalendář a plánování ve dvou', 'Cesty a rozpočty', 'Kuchařka a nákupy', 'Hlasovky'],
                'is_default' => true, 'sort_order' => 10,
            ],
            [
                'code' => 'rodina', 'name' => 'Rodina', 'tagline' => 'Když se sdílí víc než dva',
                'description' => 'Vše z tarifu Duo, navíc více členů, více prostoru a sdílení s blízkými.',
                'price_monthly' => 19_900, 'member_limit' => 6, 'storage_limit_mb' => 250_000,
                'features' => ['Vše z tarifu Duo', 'Až 6 členů', '250 GB prostoru', 'Sdílené odkazy s heslem', 'Přednostní podpora'],
                'is_default' => false, 'sort_order' => 20,
            ],
            [
                'code' => 'archiv', 'name' => 'Archiv', 'tagline' => 'Pro rozsáhlé sbírky',
                'description' => 'Neomezený počet členů a prostor pro celoživotní archiv.',
                'price_monthly' => 49_900, 'member_limit' => null, 'storage_limit_mb' => null,
                'features' => ['Vše z tarifu Rodina', 'Neomezeně členů', 'Neomezený prostor', 'Export a zálohy na vyžádání'],
                'is_default' => false, 'sort_order' => 30,
            ],
        ];

        foreach ($plans as $plan) {
            BillingPlan::updateOrCreate(['code' => $plan['code']], $plan + ['currency' => 'CZK', 'is_public' => true]);
        }

        $modules = [
            [
                'code' => 'burps', 'name' => 'Hodnocení krkanců', 'icon' => '🎺',
                'tagline' => 'První doplňkový modul. Ano, myslíme to vážně.',
                'description' => 'Zaznamenejte krkanec, nechte ho ohodnotit protějškem podle hlasitosti, délky, umění a překvapení, a sledujte žebříček i měsíčního šampiona.',
                'price_monthly' => 4_900, 'sort_order' => 10,
            ],
        ];

        foreach ($modules as $module) {
            BillingModule::updateOrCreate(['code' => $module['code']], $module + ['currency' => 'CZK', 'is_public' => true]);
        }
    }
}
