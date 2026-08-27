<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Účetní jádro pro víc subjektů, víc měn a víc peněženek.
 *
 * Dosavadní rozpočet umí jednu věc dobře: dva lidi, kteří si dělí výdaje. Tohle je jiná
 * úloha — partneři, kterými můžou být lidé i firmy, mají vlastní účty a peněženky, mezi
 * nimi se převádí, směňuje a vybírá hotovost, a to všechno se má sejít v jedné knize.
 *
 * Klíčové rozhodnutí, ze kterého plyne skoro celý zbytek: **kniha má jeden typ záznamu
 * s rozlišovačem, ne zvlášť tabulku na příjmy a zvlášť na výdaje.**
 *
 * Důvod je v tom, co se do ní musí vejít. Výběr pěti set eur z banky není výdaj — peníze
 * nikam nezmizely, jen se přesunuly z bankovní peněženky do hotovostní, a výdaj vznikne
 * až při jejich utracení. Směna osmdesáti tisíc korun na eura není ani příjem, ani výdaj:
 * skutečným nákladem je jen poplatek. Kdyby se obojí zapsalo jako běžná transakce,
 * součet výdajů by nafoukl o částky, které nikdo neutratil, a to je chyba, kterou pak
 * nikdo nenajde, protože všechno „sedí".
 *
 * Proto má záznam dvě strany — odkud a kam — a typ určuje, které z nich se vyplní:
 *
 *   příjem      jen kam        peníze přibyly zvenčí
 *   výdaj       jen odkud      peníze odešly ven
 *   převod      obojí, táž měna    přesun mezi vlastními peněženkami
 *   směna       obojí, různá měna  totéž, ale s kurzem a poplatkem
 *   výběr/vklad obojí, táž měna    zvláštní případ převodu banka ↔ hotovost
 *
 * Do příjmů a výdajů se počítají jen první dva typy. Zbytek zůstatky přesouvá, ale
 * hospodářský výsledek nemění — kromě poplatku, který je skutečný náklad a eviduje se
 * zvlášť právě proto, aby ho šlo najít.
 *
 * Původní hodnoty se nikdy nepřepočítávají. Kurz se ukládá tak, jak proběhl, a referenční
 * kurz zvlášť; kdyby se historie přepočítala podle dnešního kurzu, změnila by se čísla,
 * která už někdo viděl a odsouhlasil.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Partner je protistrana i vlastník. Může to být člověk z aplikace, člověk mimo
         * ni, firma nebo organizace — proto `kind` a nepovinná vazba na uživatele.
         */
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 20)->default('person');   // person | company | organization
            $table->string('name', 200);

            // Partner, který je zároveň uživatelem aplikace. Null u externích subjektů —
            // dodavatel nemá důvod se sem přihlašovat.
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('registration_no', 40)->nullable();   // IČO
            $table->string('vat_no', 40)->nullable();            // DIČ
            $table->string('email', 190)->nullable();
            $table->string('note', 1000)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['gallery_space_id', 'kind']);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        /*
         * Peněženka je jakékoliv místo, kde leží peníze v jedné měně: bankovní účet,
         * hotovost v kapse, karta, záloha. Jedna měna na peněženku schválně — účet, na
         * kterém leží koruny i eura zároveň, je ve skutečnosti dva účty a míchat je
         * znamená, že se zůstatek nedá spočítat bez kurzu.
         */
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();

            // Null znamená společná peněženka celého prostoru.
            $table->unsignedBigInteger('partner_id')->nullable();

            $table->string('name', 160);
            $table->string('kind', 20)->default('bank');     // bank | cash | card | other
            $table->string('currency', 3);
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->string('iban', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['gallery_space_id', 'currency']);
            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
        });

        /*
         * Projekt zastřešuje domácnost, akci i zahraniční cestu. Jedna tabulka, protože
         * z pohledu peněz je to totéž: něco, na co se utrácí a co má odpovědného člověka,
         * období a rozpočet.
         */
        Schema::create('finance_projects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 20)->default('project');  // project | household | event | trip
            $table->string('name', 200);
            $table->string('purpose', 500)->nullable();

            $table->string('country', 2)->nullable();
            $table->string('city', 120)->nullable();

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            $table->string('base_currency', 3);
            $table->unsignedBigInteger('responsible_partner_id')->nullable();

            // návrh → čeká na schválení → schváleno → aktivní → uzavřeno → archivováno
            $table->string('state', 20)->default('draft');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['gallery_space_id', 'state']);
            $table->foreign('responsible_partner_id')->references('id')->on('partners')->nullOnDelete();
        });

        /*
         * Jedna kniha pro všechno, co s penězi hne.
         *
         * Dvě strany a typ, který určuje, které se vyplní. Součty příjmů a výdajů berou
         * jen typy `income` a `expense`; zbytek zůstatky přesouvá, ale výsledek nemění.
         */
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();

            $table->string('type', 20);   // income|expense|transfer|exchange|withdrawal|deposit
            $table->date('occurred_at');
            $table->date('booked_on')->nullable();   // datum zaúčtování, když se liší

            // Odkud a kam. Který sloupec se vyplní, určuje typ.
            $table->unsignedBigInteger('wallet_from_id')->nullable();
            $table->unsignedBigInteger('wallet_to_id')->nullable();

            $table->decimal('amount_from', 14, 2)->nullable();
            $table->string('currency_from', 3)->nullable();
            $table->decimal('amount_to', 14, 2)->nullable();
            $table->string('currency_to', 3)->nullable();

            /*
             * Kurz, jak doopravdy proběhl, a referenční zvlášť. Referenční se ukládá
             * jako snímek k datu — kdyby se dopočítával, změnila by pozdější aktualizace
             * kurzů čísla v uzavřeném vyúčtování.
             */
            $table->decimal('rate', 16, 8)->nullable();
            $table->decimal('reference_rate', 16, 8)->nullable();
            $table->string('rate_source', 40)->nullable();     // ČNB, ECB, banka…

            // Poplatek je jediná část směny, která je skutečným nákladem.
            $table->decimal('fee_amount', 14, 2)->default(0);
            $table->string('fee_currency', 3)->nullable();

            $table->unsignedBigInteger('finance_project_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();

            // Kdo zaplatil a pro koho to bylo. Dvě různé věci: nájem platí jeden,
            // ale je pro oba.
            $table->unsignedBigInteger('payer_partner_id')->nullable();
            $table->unsignedBigInteger('beneficiary_partner_id')->nullable();

            $table->string('counterparty', 200)->nullable();   // obchodník, dodavatel
            $table->string('payment_method', 30)->nullable();  // card | cash | transfer | direct_debit
            $table->string('description', 500)->nullable();

            $table->unsignedBigInteger('receipt_media_id')->nullable();

            // draft → pending → approved → settled; rejected je slepá ulička.
            $table->string('state', 20)->default('approved');

            $table->foreignId('created_by')->constrained('users');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['gallery_space_id', 'occurred_at']);
            $table->index(['gallery_space_id', 'type']);
            $table->index('finance_project_id');

            $table->foreign('wallet_from_id')->references('id')->on('wallets')->nullOnDelete();
            $table->foreign('wallet_to_id')->references('id')->on('wallets')->nullOnDelete();
            $table->foreign('finance_project_id')->references('id')->on('finance_projects')->nullOnDelete();
            $table->foreign('payer_partner_id')->references('id')->on('partners')->nullOnDelete();
            $table->foreign('beneficiary_partner_id')->references('id')->on('partners')->nullOnDelete();
            $table->foreign('receipt_media_id')->references('id')->on('media_items')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });

        /*
         * Rozdělení výdaje mezi partnery.
         *
         * Vlastní tabulka, ne sloupec: způsobů dělení je šest (rovným dílem, procentem,
         * pevnou částkou, jen někomu, podle osob, podle dnů) a všechny končí u téhož —
         * u seznamu partnerů s částkami. Ukládá se výsledek, ne předpis, protože předpis
         * se dá spočítat znovu jinak, kdežto částka, na které se lidé dohodli, ne.
         */
        Schema::create('transaction_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();

            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            // Čím to bylo spočítané — jen pro vysvětlení v přehledu, ne pro přepočet.
            $table->string('basis', 20)->nullable();   // equal|percent|fixed|persons|days|weight
            $table->decimal('basis_value', 12, 4)->nullable();

            $table->timestamps();
            $table->unique(['transaction_id', 'partner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_shares');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('finance_projects');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('partners');
    }
};
