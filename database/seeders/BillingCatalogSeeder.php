<?php

namespace Database\Seeders;

use App\Models\BillingModule;
use App\Models\BillingPlan;
use App\Models\Feature;
use Illuminate\Database\Seeder;

/**
 * The offering: the feature catalogue, the plans, the add-ons and the matrix binding them.
 *
 * Prices are in minor units (haléře). Everything is keyed by code, so the seeder is safe to
 * re-run and will not duplicate or reset an operator's later edits to prices.
 */
class BillingCatalogSeeder extends Seeder
{
    /**
     * The catalogue. `core` features can never be locked or hidden — without them there is
     * no product. Everything else can be granted per plan and hidden by the customer.
     */
    private function features(): array
    {
        return [
            // Core — always on, in every plan.
            ['code' => 'gallery',      'name' => 'Galerie a alba',        'category' => 'Základ',   'icon' => '🖼️', 'route' => '/timeline', 'is_core' => true,  'is_optional' => false, 'tagline' => 'Fotky, videa, alba a časová osa.'],
            ['code' => 'search',       'name' => 'Vyhledávání',           'category' => 'Základ',   'icon' => '🔍', 'route' => '/search',   'is_core' => true,  'is_optional' => false, 'tagline' => 'Hledání napříč vším, co máte uložené.'],
            ['code' => 'sharing',      'name' => 'Sdílené odkazy',        'category' => 'Základ',   'icon' => '🔗', 'route' => '/shared-memories', 'is_core' => false, 'is_optional' => true, 'tagline' => 'Odkazy s heslem a platností pro rodinu a přátele.'],

            // Everyday life together.
            ['code' => 'calendar',     'name' => 'Kalendář a plánování',  'category' => 'Společný život', 'icon' => '📅', 'route' => '/calendar',  'tagline' => 'Akce, úkoly, připomínky a dostupnost obou.'],
            ['code' => 'recipes',      'name' => 'Kuchařka',              'category' => 'Společný život', 'icon' => '🍳', 'route' => '/recipes',   'tagline' => 'Recepty, jídelníček a nákupní seznamy.'],
            ['code' => 'memories',     'name' => 'Vzpomínky a příběhy',   'category' => 'Společný život', 'icon' => '💞', 'route' => '/memories',  'tagline' => 'Časové kapsle, výročí a společná ohlédnutí.'],
            ['code' => 'people',       'name' => 'Lidé',                  'category' => 'Společný život', 'icon' => '👥', 'route' => '/people',    'tagline' => 'Kdo je na fotkách a co k nim patří.'],
            ['code' => 'places',       'name' => 'Místa',                 'category' => 'Společný život', 'icon' => '📍', 'route' => '/places',    'tagline' => 'Podniky, výlety a hodnocení návštěv.'],
            ['code' => 'voice_notes',  'name' => 'Hlasovky',              'category' => 'Společný život', 'icon' => '🎙️', 'route' => '/hlasovky', 'tagline' => 'Krátké vzkazy, na které není potřeba psát.'],
            ['code' => 'journal',      'name' => 'Deník',                 'category' => 'Společný život', 'icon' => '📔', 'route' => '/denik',     'tagline' => 'Soukromé zápisky, které sdílíte, až když chcete.'],
            ['code' => 'chat',         'name' => 'Chat',                  'category' => 'Společný život', 'icon' => '💬', 'route' => '/chat',      'tagline' => 'Živá konverzace pro pár i celou skupinu.'],

            // Planning bigger things.
            ['code' => 'trips',        'name' => 'Cesty a itinerář',      'category' => 'Plánování', 'icon' => '🧭', 'route' => '/trips',     'tagline' => 'Trasy, dny, doklady, balení a offline režim.'],
            ['code' => 'finance',      'name' => 'Společné finance',      'category' => 'Plánování', 'icon' => '💰', 'route' => '/finances',  'tagline' => 'Výdaje, podíly, vyrovnání a rozpočty cest.'],
            ['code' => 'gifts',        'name' => 'Dárky a výročí',        'category' => 'Plánování', 'icon' => '🎁', 'route' => '/gifts-anniversaries', 'tagline' => 'Nápady, rozpočty a připomínky včas.'],
            ['code' => 'watchlist',    'name' => 'Filmy a seriály',       'category' => 'Plánování', 'icon' => '🎬', 'route' => '/watchlist/movies',    'tagline' => 'Společný seznam, hodnocení a filmové večery.'],
            ['code' => 'date_ideas',   'name' => 'Nápady na randíčka',    'category' => 'Plánování', 'icon' => '💡', 'route' => '/date-ideas',  'tagline' => 'Návrhy podle rozpočtu, počasí a nálady.'],

            // Extras that not everyone wants.
            ['code' => 'photobook',    'name' => 'Fotoknihy a tisk',      'category' => 'Nadstavba', 'icon' => '📔', 'route' => '/print',     'tagline' => 'Příprava tiskových výstupů s kontrolou kvality.'],
            ['code' => 'vault',        'name' => 'Soukromý trezor',       'category' => 'Nadstavba', 'icon' => '🔐', 'route' => '/vault',     'tagline' => 'Obsah jen pro vaše oči, chráněný heslem.'],
            ['code' => 'tv_mode',      'name' => 'TV režim',              'category' => 'Nadstavba', 'icon' => '📺', 'route' => '/tv',        'tagline' => 'Promítání na televizi nebo velké obrazovce.'],
            ['code' => 'stats',        'name' => 'Statistiky',            'category' => 'Nadstavba', 'icon' => '📊', 'route' => '/stats',     'tagline' => 'Přehledy o tom, co a kdy jste nasbírali.'],
            ['code' => 'automations',  'name' => 'Automatizace',          'category' => 'Nadstavba', 'icon' => '⚙️', 'route' => '/automations', 'tagline' => 'Pravidelné úlohy, které běží za vás.'],

            // Paid add-ons.
            ['code' => 'burps',        'name' => 'Hodnocení krkanců',     'category' => 'Doplňky',   'icon' => '🎺', 'route' => '/krkance',   'tagline' => 'Ano, myslíme to vážně. A je to zábava.'],
            ['code' => 'farts',        'name' => 'Hodnocení prdů',        'category' => 'Doplňky',   'icon' => '💨', 'route' => '/prdy',      'tagline' => 'Hlasitost, aroma, nenápadnost a načasování.'],
        ];
    }

    public function run(): void
    {
        $order = 0;
        foreach ($this->features() as $feature) {
            Feature::updateOrCreate(
                ['code' => $feature['code']],
                $feature + ['is_core' => false, 'is_optional' => true, 'sort_order' => $order += 10]
            );
        }

        // Feature sets, from the smallest plan upwards.
        // Chat belongs to every plan: talking to each other is the point of a couples'
        // app, not an upsell. Larger plans get it by inheriting this set.
        $couple = ['gallery', 'search', 'sharing', 'calendar', 'recipes', 'memories', 'people', 'places', 'voice_notes', 'date_ideas', 'journal', 'chat'];
        $family = [...$couple, 'trips', 'finance', 'gifts', 'watchlist', 'stats'];
        $group  = [...$family, 'photobook', 'vault', 'tv_mode', 'automations'];

        $plans = [
            [
                'code' => 'duo', 'group_type' => 'couple', 'name' => 'Duo',
                'tagline' => 'Pro dva, kteří si chtějí pamatovat všechno',
                'description' => 'Sdílená galerie, kalendář, kuchařka, vzpomínky a hlasovky. Vše, co spolu potřebujete den po dni.',
                'price_monthly' => 0, 'price_yearly' => 0,
                'member_limit' => 2, 'storage_limit_mb' => 25_000,
                'features' => ['Sdílená galerie a alba', 'Kalendář a plánování ve dvou', 'Kuchařka a nákupy', 'Vzpomínky a výročí', 'Hlasovky'],
                'is_default' => true, 'highlight' => false, 'sort_order' => 10,
                'grants' => $couple,
            ],
            [
                'code' => 'duo_plus', 'group_type' => 'couple', 'name' => 'Duo Plus',
                'tagline' => 'Pro dvojice, které hodně cestují',
                'description' => 'Vše z tarifu Duo a k tomu cesty, společné finance, dárky a filmový watchlist.',
                'price_monthly' => 14_900, 'price_yearly' => 149_000,
                'member_limit' => 2, 'storage_limit_mb' => 150_000,
                'features' => ['Vše z tarifu Duo', 'Cesty a itinerář', 'Společné finance a vyrovnání', 'Dárky a výročí', 'Filmy a seriály', '150 GB prostoru'],
                'is_default' => false, 'highlight' => true, 'sort_order' => 20,
                'grants' => $family,
            ],
            [
                'code' => 'rodina', 'group_type' => 'family', 'name' => 'Rodina',
                'tagline' => 'Když se sdílí víc než dva',
                'description' => 'Až šest členů, sdílení s blízkými a všechny plánovací funkce.',
                'price_monthly' => 24_900, 'price_yearly' => 249_000,
                'member_limit' => 6, 'storage_limit_mb' => 400_000,
                'features' => ['Vše z tarifu Duo Plus', 'Až 6 členů', '400 GB prostoru', 'Fotoknihy a tisk', 'Soukromý trezor', 'TV režim'],
                'is_default' => false, 'highlight' => false, 'sort_order' => 30,
                'grants' => $group,
            ],
            [
                'code' => 'skupina', 'group_type' => 'group', 'name' => 'Skupina',
                'tagline' => 'Pro spolky, týmy a větší party',
                'description' => 'Neomezený počet členů i prostoru, všechny funkce a automatizace.',
                'price_monthly' => 49_900, 'price_yearly' => 499_000,
                'member_limit' => null, 'storage_limit_mb' => null,
                'features' => ['Vše z tarifu Rodina', 'Neomezeně členů', 'Neomezený prostor', 'Automatizace', 'Export a zálohy na vyžádání'],
                'is_default' => false, 'highlight' => false, 'sort_order' => 40,
                'grants' => $group,
            ],
        ];

        foreach ($plans as $definition) {
            $grants = $definition['grants'];
            unset($definition['grants']);

            $plan = BillingPlan::updateOrCreate(
                ['code' => $definition['code']],
                $definition + ['currency' => 'CZK', 'is_public' => true]
            );
            $plan->grantedFeatures()->sync(Feature::whereIn('code', $grants)->pluck('id')->all());
        }

        $modules = [
            // Capacity rather than features: it grants nothing new to do, only room to
            // keep doing it. Sorted first because it is the add-on people reach for when
            // something has stopped working, not one they browse for.
            [
                'code' => 'uloziste_100', 'name' => '100 GB navíc', 'icon' => '💾',
                'tagline' => 'Rozšíření místa na serveru galerie.',
                'description' => 'Přidá 100 GB ke kapacitě vašeho tarifu. Sčítá se, takže lze pořídit i vícekrát.',
                'price_monthly' => 9_900, 'storage_bonus_mb' => 100_000, 'sort_order' => 1,
                'grants' => [],
            ],
            // Capacity, in the sizes people actually run out at. All three stack, so
            // somebody who needs two terabytes buys the big one twice rather than waiting
            // for us to invent a bigger tier.
            [
                'code' => 'uloziste_500', 'name' => '500 GB navíc', 'icon' => '💾',
                'tagline' => 'Větší rozšíření místa na serveru.',
                'description' => 'Přidá 500 GB ke kapacitě tarifu. Výhodnější než pětkrát sto.',
                'price_monthly' => 39_900, 'storage_bonus_mb' => 500_000, 'sort_order' => 2,
                'grants' => [],
            ],
            [
                'code' => 'uloziste_1tb', 'name' => '1 TB navíc', 'icon' => '🗄️',
                'tagline' => 'Pro knihovny, které se počítají v terabajtech.',
                'description' => 'Přidá 1 TB ke kapacitě tarifu. Sčítá se s ostatními rozšířeními.',
                'price_monthly' => 69_900, 'storage_bonus_mb' => 1_000_000, 'sort_order' => 3,
                'grants' => [],
            ],

            // Feature bundles. Each unlocks things the cheaper plans leave out, grouped by
            // what somebody is actually trying to do rather than by which screen it lives
            // on — nobody wants "the places module", they want to plan a holiday.
            [
                'code' => 'cestovatel', 'name' => 'Cestovatel', 'icon' => '🧭',
                'tagline' => 'Plánování cest a míst na jednom místě.',
                'description' => 'Itineráře, ubytování a doprava k cestám, plus mapa navštívených míst.',
                'price_monthly' => 9_900, 'sort_order' => 40,
                'grants' => ['trips', 'places'],
            ],
            [
                'code' => 'spolecne_finance', 'name' => 'Společné finance', 'icon' => '💰',
                'tagline' => 'Kdo co zaplatil a jak jste vyrovnaní.',
                'description' => 'Společné výdaje, vyrovnání mezi vámi a přehled, kam peníze odcházejí.',
                'price_monthly' => 7_900, 'sort_order' => 41,
                'grants' => ['finance'],
            ],
            [
                'code' => 'kronikar', 'name' => 'Kronikář', 'icon' => '📖',
                'tagline' => 'Deník, fotokniha a statistiky.',
                'description' => 'Osobní i sdílený deník, sazba fotoknihy z alba a přehledy o tom, co jste nafotili.',
                'price_monthly' => 11_900, 'sort_order' => 42,
                'grants' => ['journal', 'photobook', 'stats'],
            ],
            [
                'code' => 'vecerni_program', 'name' => 'Večerní program', 'icon' => '🍿',
                'tagline' => 'Filmy, seriály a nápady na rande.',
                'description' => 'Společný watchlist s tierlisty a generátor nápadů, co spolu podniknout.',
                'price_monthly' => 6_900, 'sort_order' => 43,
                'grants' => ['watchlist', 'date_ideas'],
            ],
            [
                'code' => 'trezor', 'name' => 'Trezor', 'icon' => '🔒',
                'tagline' => 'Soukromá část galerie za druhým heslem.',
                'description' => 'Fotky, které nemají být vidět jen tak — oddělené a zamčené zvlášť.',
                'price_monthly' => 8_900, 'sort_order' => 44,
                'grants' => ['vault'],
            ],
            [
                'code' => 'automatizace', 'name' => 'Automatizace', 'icon' => '⚙️',
                'tagline' => 'Vlastní pravidla: když se stane tohle, udělej tamto.',
                'description' => 'Pravidla nad kalendářem, fotkami a úkoly, která běží sama.',
                'price_monthly' => 9_900, 'sort_order' => 45,
                'grants' => ['automations'],
            ],
            [
                'code' => 'obyvak', 'name' => 'Obývák', 'icon' => '📺',
                'tagline' => 'Galerie na televizi.',
                'description' => 'Režim pro velkou obrazovku — fotky běží samy, ovládá se z telefonu.',
                'price_monthly' => 4_900, 'sort_order' => 46,
                'grants' => ['tv_mode'],
            ],
            [
                'code' => 'darky_a_vyroci', 'name' => 'Dárky a výročí', 'icon' => '🎁',
                'tagline' => 'Aby vám nic neuteklo.',
                'description' => 'Seznam nápadů na dárky, hlídání výročí a připomínky s předstihem.',
                'price_monthly' => 5_900, 'sort_order' => 47,
                'grants' => ['gifts'],
            ],

            [
                'code' => 'burps', 'name' => 'Hodnocení krkanců', 'icon' => '🎺',
                'tagline' => 'První doplňkový modul. Ano, myslíme to vážně.',
                'description' => 'Zaznamenejte krkanec, nechte ho ohodnotit protějškem podle hlasitosti, délky, umění a překvapení, a sledujte žebříček i měsíčního šampiona.',
                'price_monthly' => 4_900, 'sort_order' => 10,
                'grants' => ['burps'],
            ],
            [
                'code' => 'farts', 'name' => 'Hodnocení prdů', 'icon' => '💨',
                'tagline' => 'Sesterský modul ke krkancům, s vlastními kritérii.',
                'description' => 'Zaznamenejte úlovek nahrávkou nebo připojenou hlasovkou a nechte ho ohodnotit podle hlasitosti, aroma, nenápadnosti a načasování. Vlastní žebříček i šampion měsíce.',
                'price_monthly' => 4_900, 'sort_order' => 20,
                'grants' => ['farts'],
            ],
            [
                // Cheaper together than the two bought separately.
                'code' => 'zvukove_hratky', 'name' => 'Zvukové hrátky', 'icon' => '🔊',
                'tagline' => 'Krkance i prdy v jednom balíčku.',
                'description' => 'Oba doplňkové moduly dohromady, se společným nahráváním zvuku a napojením na hlasovky.',
                'price_monthly' => 7_900, 'sort_order' => 30,
                'grants' => ['burps', 'farts'],
            ],
        ];

        foreach ($modules as $definition) {
            $grants = $definition['grants'];
            unset($definition['grants']);

            $module = BillingModule::updateOrCreate(
                ['code' => $definition['code']],
                $definition + ['currency' => 'CZK', 'is_public' => true]
            );
            $module->grantedFeatures()->sync(Feature::whereIn('code', $grants)->pluck('id')->all());
        }

        // Core features are marked after the fact so the list above stays readable.
        Feature::whereIn('code', ['gallery', 'search'])->update(['is_core' => true, 'is_optional' => false]);
    }
}
