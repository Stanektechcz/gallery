<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dva indexy, které se do klíče InnoDB nevešly.
 *
 * `albums.materialized_path` a `push_subscriptions.endpoint` mají 2048 znaků,
 * což je v utf8mb4 8192 bajtů proti stropu 3072. Na čisté MySQL padalo
 * `migrate` na ERROR 1071 hned u čtvrté migrace; SQLite délky klíčů ignoruje,
 * takže na vývoji i v testech všechno prošlo.
 *
 * Původní migrace jsou opravené kvůli novým prostředím. Tahle dorovnává
 * databázi, která už běží: jednotlivé kroky se dělají jen tehdy, když je
 * opravdu co měnit, takže je bezpečné ji pustit na obě.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->cestaAlba();
        $this->otiskOdberu();
    }

    /** Index na cestu stromem alb — na MySQL jen po prvních 191 znaků. */
    private function cestaAlba(): void
    {
        if (! Schema::hasTable('albums') || DB::getDriverName() !== 'mysql') {
            return;
        }

        $index = collect(DB::select('SHOW INDEX FROM albums'))
            ->firstWhere('Key_name', 'albums_materialized_path_index');

        // Už je s prefixem (nebo ho MySQL nikdy nepřijala) — není co dělat.
        if ($index !== null && (int) ($index->Sub_part ?? 0) > 0) {
            return;
        }

        if ($index !== null) {
            DB::statement('DROP INDEX albums_materialized_path_index ON albums');
        }

        DB::statement('CREATE INDEX albums_materialized_path_index ON albums (materialized_path(191))');
    }

    /**
     * Jednoznačnost odběru upozornění přebírá otisk adresy.
     *
     * Prefix by tu nestačil: dvě adresy téhož poskytovatele se liší až na
     * konci a unikát nad prvními 191 znaky by je prohlásil za tutéž — druhý
     * telefon by tiše přepsal odběr toho prvního.
     */
    private function otiskOdberu(): void
    {
        if (! Schema::hasTable('push_subscriptions') || Schema::hasColumn('push_subscriptions', 'endpoint_hash')) {
            return;
        }

        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->char('endpoint_hash', 64)->nullable()->after('endpoint');
        });

        DB::table('push_subscriptions')->orderBy('id')->chunkById(500, function ($radky) {
            foreach ($radky as $radek) {
                DB::table('push_subscriptions')
                    ->where('id', $radek->id)
                    ->update(['endpoint_hash' => hash('sha256', (string) $radek->endpoint)]);
            }
        });

        /*
         * Dva odběry se stejnou adresou by po doplnění otisku srazily unikát.
         * Vzniknout mohly jen tam, kde MySQL původní unikát nepřijala; zůstává
         * ten nejnovější, protože ten je ten živý.
         */
        $duplicity = DB::table('push_subscriptions')
            ->select('endpoint_hash')
            ->groupBy('endpoint_hash')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('endpoint_hash');

        foreach ($duplicity as $otisk) {
            $nejnovejsi = DB::table('push_subscriptions')->where('endpoint_hash', $otisk)->max('id');
            DB::table('push_subscriptions')->where('endpoint_hash', $otisk)->where('id', '!=', $nejnovejsi)->delete();
        }

        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->unique('endpoint_hash');
        });

        if (DB::getDriverName() === 'mysql') {
            // Starý unikát na dlouhém sloupci, pokud ho databáze vůbec přijala.
            try {
                DB::statement('ALTER TABLE push_subscriptions DROP INDEX push_subscriptions_endpoint_unique');
            } catch (Throwable) {
                // Neexistoval — právě proto, že se do klíče nevešel.
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('push_subscriptions', 'endpoint_hash')) {
            return;
        }

        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropUnique(['endpoint_hash']);
            $table->dropColumn('endpoint_hash');
        });
    }
};
