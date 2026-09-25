<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Jedna faktura na platbu — hlídá i databáze.
 *
 * `InvoiceService::forPayment` fakturu k zaplacené platbě hledá pod zámkem;
 * unikátní index je druhá pojistka pro případ, že by zámek někdy selhal.
 *
 * Existující dvojice se **nemažou** ani neslučují: faktura je daňový doklad
 * a její číslo se nesmí ztratit. Když v tabulce dvojice už jsou, index se
 * nezaloží, migrace jen zapíše varování a projde — dvojice musí posoudit
 * člověk. Tahle migrace se i tak zapíše jako proběhlá, po vyřešení dvojic je
 * proto potřeba index doplnit novou migrací.
 *
 * Víc faktur bez platby (`payment_id = null`) index v SQLite i MySQL dovolí.
 */
return new class extends Migration
{
    private const INDEX = 'invoices_payment_id_unique';

    public function up(): void
    {
        if (! Schema::hasTable('invoices') || Schema::hasIndex('invoices', self::INDEX)) {
            return;
        }

        $dvojice = DB::table('invoices')
            ->whereNotNull('payment_id')
            ->groupBy('payment_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('payment_id');

        if ($dvojice->isNotEmpty()) {
            Log::warning('invoices.payment_id: unikátní index nezaložen, k některým platbám existuje víc faktur', [
                'payment_ids' => $dvojice->all(),
            ]);

            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->unique('payment_id', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasIndex('invoices', self::INDEX)) {
            return;
        }

        /*
         * MySQL si k cizímu klíči index založí sám a po přidání unikátního ho
         * smí potichu zahodit. Bez obyčejného indexu na `payment_id` by pak
         * odebrání unikátního spadlo na „needed in a foreign key constraint".
         */
        $obycejny = collect(Schema::getIndexes('invoices'))
            ->contains(fn (array $index) => $index['columns'] === ['payment_id'] && ! $index['unique']);

        Schema::table('invoices', function (Blueprint $table) use ($obycejny) {
            if (! $obycejny) {
                $table->index('payment_id', 'invoices_payment_id_index');
            }
            $table->dropUnique(self::INDEX);
        });
    }
};
