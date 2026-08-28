<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Základ modulu Rozpočet: jedna kniha, globální kategorie, cesty a limity nad nimi.
 *
 * Dosud stály vedle sebe dvě evidence téhož. `budget_entries` patří rozpočtu a mimo něj
 * neexistují; `transactions` jsou kniha s účty, měnami a kurzy. Dvě pravdy o jedné
 * útratě znamenají, že se dřív nebo později rozejdou a nikdo nepozná která platí.
 *
 * Modul proto stojí na `transactions`. Rozhodlo o tom, co v nich už je — typ, účet
 * odkud a kam, kurz, poplatek, plátce, komu výdaj náleží — a hlavně to, že jsou
 * prázdné. Žádná živá data se nepřepisují.
 *
 * Kategorie se odpojují od rozpočtu. `budget_categories` patří jednomu rozpočtu, takže
 * „Potraviny" v lednu a „Potraviny" v únoru jsou dvě různé věci a statistika napříč
 * měsíci je neumí sečíst. Tady jsou kategorie vlastnictvím prostoru a rozpočet jim
 * jen nastavuje limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Kategorie patří prostoru, ne rozpočtu.
         *
         * `kind` odděluje výdajové od příjmových, protože „Mzda" nemá co dělat v nabídce
         * u výdaje. `is_favourite` řídí, co se ukáže v rychlé volbě u formuláře — tam se
         * vejde šest dlaždic a ostatní jsou za tlačítkem.
         */
        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();

            $table->string('name', 80);
            $table->string('kind', 10)->default('expense');   // expense | income
            $table->string('icon', 40)->nullable();
            $table->string('color', 20)->nullable();
            $table->boolean('is_favourite')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Výchozí volby, které kategorie napovídá formuláři. Nájem se skoro vždycky
            // platí z téhož účtu a dělí stejně — předvyplnit to ušetří dvě klepnutí.
            $table->unsignedBigInteger('default_wallet_id')->nullable();
            $table->string('default_split', 20)->nullable();  // equal | adri | maki | custom
            $table->decimal('default_split_value', 6, 2)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['gallery_space_id', 'kind', 'sort_order']);
            $table->foreign('default_wallet_id')->references('id')->on('wallets')->nullOnDelete();
        });

        /*
         * Doplnění knihy o to, co spec vyžaduje a co v ní chybělo.
         */
        Schema::table('transactions', function (Blueprint $table) {
            // Poplatek: je uvnitř částek, nebo se platil navíc? Bez téhle informace se
            // efektivní kurz spočítat nedá — stejná trojice čísel znamená dva různé
            // kurzy podle toho, jestli už je poplatek v odepsané částce obsažený.
            $table->boolean('fee_included')->default(false)->after('fee_currency');

            // Poskytovatel směny. `counterparty` je obchodník u výdaje; míchat do něj
            // Revolut by znamenalo, že se směny objeví mezi obchody v statistice.
            $table->string('provider', 60)->nullable()->after('counterparty');
            $table->string('place', 120)->nullable()->after('provider');

            // Výdaj mimo rozpočet — musí mít důvod, jinak se za půl roku nikdo nedozví,
            // proč se ta částka nikde nepočítá.
            $table->boolean('excluded_from_budget')->default(false)->after('place');
            $table->string('exclusion_reason', 200)->nullable()->after('excluded_from_budget');

            // Refundace ukazuje na původní výdaj: snižuje čisté čerpání jeho kategorie,
            // ale v seznamu zůstává vidět samostatně.
            $table->unsignedBigInteger('refund_of_id')->nullable()->after('exclusion_reason');

            // Vyrovnání mezi partnery. Je to převod, který mění saldo a přitom není
            // příjem ani výdaj — bez příznaku by se nedal odlišit od běžného převodu.
            $table->boolean('is_settlement')->default(false)->after('refund_of_id');

            $table->foreign('refund_of_id')->references('id')->on('transactions')->nullOnDelete();
            $table->foreign('category_id')->references('id')->on('finance_categories')->nullOnDelete();
            // Index na (gallery_space_id, occurred_at) zakládá už migrace knihy.
        });

        /*
         * Cesta. `finance_projects` už měla zemi, město, termín i stav — chybělo jí
         * to, co z ní dělá rozpočet: částka, rezerva a výchozí volby pro zápis.
         */
        Schema::table('finance_projects', function (Blueprint $table) {
            $table->decimal('budget_amount', 14, 2)->nullable()->after('base_currency');
            $table->decimal('reserve_amount', 14, 2)->nullable()->after('budget_amount');
            $table->unsignedBigInteger('default_wallet_id')->nullable()->after('reserve_amount');

            // Jedna cesta smí být aktivní. Předvyplňuje se do nových záznamů, takže dvě
            // aktivní by znamenaly, že se výdaje tiše rozdělí mezi dva pobyty.
            $table->boolean('is_active')->default(false)->after('default_wallet_id');
            $table->text('note')->nullable()->after('is_active');

            $table->foreign('default_wallet_id')->references('id')->on('wallets')->nullOnDelete();
        });

        /*
         * Rozpočty modulu počítají z knihy, ne z vlastních položek.
         *
         * `scope` odděluje nové od starých. Bez něj by jedna služba musela u každého
         * rozpočtu hádat, odkud brát útraty, a stávající rozpočty by se rozbily.
         */
        Schema::table('budgets', function (Blueprint $table) {
            $table->string('scope', 12)->default('entries')->after('period_mode'); // entries | ledger
            $table->string('budget_kind', 12)->default('monthly')->after('scope');  // monthly | trip | category
            $table->unsignedBigInteger('finance_project_id')->nullable()->after('budget_kind');
            $table->decimal('reserve_amount', 14, 2)->nullable()->after('finance_project_id');

            // Hranice upozornění. Výchozí 80/90/100, ale musí jít změnit — u krátké cesty
            // je osmdesát procent hláška, která přijde první den.
            $table->string('alert_thresholds', 40)->default('80,90,100')->after('reserve_amount');

            $table->foreign('finance_project_id')->references('id')->on('finance_projects')->nullOnDelete();
        });

        /*
         * Limit kategorie uvnitř rozpočtu.
         *
         * Zvlášť od kategorie samotné: „Potraviny 400 €" platí pro tenhle pobyt, ne
         * navždycky. Uložit limit do kategorie by znamenalo, že ho další cesta zdědí.
         */
        Schema::create('budget_category_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finance_category_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->unique(['budget_id', 'finance_category_id']);
        });

        /*
         * Šablony rychlého zápisu — „Potraviny, EUR karta, Maki, společné 50/50".
         * Částku nepředvyplňují nikdy; ta je jediné, co se pokaždé liší.
         */
        Schema::create('finance_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();

            $table->string('name', 80);
            $table->string('type', 20)->default('expense');
            $table->unsignedBigInteger('finance_category_id')->nullable();
            $table->unsignedBigInteger('wallet_id')->nullable();
            $table->unsignedBigInteger('payer_partner_id')->nullable();
            $table->string('split', 20)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamps();

            $table->foreign('finance_category_id')->references('id')->on('finance_categories')->nullOnDelete();
            $table->foreign('wallet_id')->references('id')->on('wallets')->nullOnDelete();
            $table->foreign('payer_partner_id')->references('id')->on('partners')->nullOnDelete();
        });

        /*
         * Nastavení modulu. Jeden řádek na prostor — sekce 18 specifikace.
         */
        Schema::create('finance_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete()->unique();

            $table->string('name', 120)->default('Náš rozpočet');
            $table->string('home_currency', 3)->default('CZK');
            $table->string('travel_currency', 3)->default('EUR');
            $table->string('default_period', 20)->default('month');
            $table->string('default_split', 20)->default('equal');
            $table->decimal('default_split_value', 6, 2)->default(50);
            $table->boolean('show_partner_balance')->default(true);
            $table->boolean('show_czk_hint')->default(true);
            $table->string('conversion_method', 20)->default('acquisition'); // acquisition | reference | manual
            $table->decimal('default_reserve', 14, 2)->nullable();
            $table->string('alert_thresholds', 40)->default('80,90,100');
            $table->boolean('warn_duplicates')->default(true);
            $table->boolean('warn_unusual_amount')->default(true);
            $table->boolean('warn_low_balance')->default(true);
            $table->string('default_tab', 20)->default('prehled');
            $table->string('list_density', 12)->default('comfortable');
            $table->unsignedBigInteger('default_wallet_czk')->nullable();
            $table->unsignedBigInteger('default_wallet_eur')->nullable();

            $table->timestamps();

            $table->foreign('default_wallet_czk')->references('id')->on('wallets')->nullOnDelete();
            $table->foreign('default_wallet_eur')->references('id')->on('wallets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_settings');
        Schema::dropIfExists('finance_templates');
        Schema::dropIfExists('budget_category_limits');

        Schema::table('budgets', function (Blueprint $table) {
            $table->dropForeign(['finance_project_id']);
            $table->dropColumn(['scope', 'budget_kind', 'finance_project_id', 'reserve_amount', 'alert_thresholds']);
        });

        Schema::table('finance_projects', function (Blueprint $table) {
            $table->dropForeign(['default_wallet_id']);
            $table->dropColumn(['budget_amount', 'reserve_amount', 'default_wallet_id', 'is_active', 'note']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['refund_of_id']);
            $table->dropForeign(['category_id']);
            $table->dropColumn([
                'fee_included', 'provider', 'place',
                'excluded_from_budget', 'exclusion_reason', 'refund_of_id', 'is_settlement',
            ]);
        });

        Schema::dropIfExists('finance_categories');
    }
};
