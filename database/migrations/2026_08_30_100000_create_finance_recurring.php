<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pravidelný výdaj — nájem a spol.
 *
 * Nájem 280 € měsíčně je na půlročním pobytu šest zápisů, které se dají zapomenout,
 * a hlavně je to jediná položka, o které se **ví dopředu**. Bez ní rozpočet tvrdí,
 * že zbývá víc, než kolik doopravdy zbývá: čtyři nezaplacené nájmy jsou tisíc sto
 * dvacet eur, které už mají majitele, i když ještě leží na účtu.
 *
 * Předpis je vzor, ne transakce. Sám o sobě zůstatek nemění — teprve když se z něj
 * vytvoří zápis, jde o skutečné peníze. Díky tomu jde předpis založit dopředu na celý
 * pobyt a přitom se do zůstatků promítnou jen ty splátky, které opravdu odešly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_recurring', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('type', 20)->default('expense');   // expense | income
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);

            $table->unsignedBigInteger('wallet_id')->nullable();
            $table->unsignedBigInteger('finance_category_id')->nullable();
            $table->unsignedBigInteger('finance_project_id')->nullable();
            $table->unsignedBigInteger('payer_partner_id')->nullable();
            $table->string('split', 20)->nullable();

            // Kolikátého to chodí. Měsíce kratší než den v měsíci se ošetřují při
            // generování — třicátého prvního února nic nechodí a posune se na konec.
            $table->unsignedTinyInteger('day_of_month')->default(1);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();

            // Do kdy je vygenerováno. Bez toho by se po každém otevření stránky
            // vytvořily splátky znovu.
            $table->date('generated_until')->nullable();

            // Kdo předpis založil. Splátky z něj se zapisují na jeho jméno — bez toho
            // by transakce vznikaly bez autora a nešlo by dohledat, odkud se vzaly.
            $table->foreignId('created_by')->constrained('users');

            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['gallery_space_id', 'is_active']);
            $table->foreign('wallet_id')->references('id')->on('wallets')->nullOnDelete();
            $table->foreign('finance_category_id')->references('id')->on('finance_categories')->nullOnDelete();
            $table->foreign('finance_project_id')->references('id')->on('finance_projects')->nullOnDelete();
            $table->foreign('payer_partner_id')->references('id')->on('partners')->nullOnDelete();
        });

        Schema::table('transactions', function (Blueprint $table) {
            // Ze kterého předpisu záznam vznikl. Podle toho se pozná, které splátky
            // už existují, a smazání předpisu nechá zapsané splátky být.
            $table->unsignedBigInteger('recurring_id')->nullable()->after('refund_of_id');
            $table->foreign('recurring_id')->references('id')->on('finance_recurring')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['recurring_id']);
            $table->dropColumn('recurring_id');
        });

        Schema::dropIfExists('finance_recurring');
    }
};
