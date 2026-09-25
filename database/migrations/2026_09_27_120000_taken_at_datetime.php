<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Datum pořízení fotky z let před 1970.
 *
 * `media_items.taken_at` byl `timestamp` — na MySQL rozsah jen 1970-01-01 až
 * 2038-01-19. Úklid přitom datuje skeny do let dávno před tím („1965") a úprava
 * fotky bere jakýkoli čtyřmístný rok; na produkci (STRICT) takový zápis spadl
 * a s ním celý `PATCH /api/state`. SQLite v testech rozsah nehlídá, takže to
 * nikde nebylo vidět.
 *
 * `DATETIME` má rozsah 1000–9999 a — což je tu podstatné — **nepřevádí pásma**.
 * `taken_at` je čas podle hodin (viz `Cas::zHodin`): ukládá se tak, jak ho
 * člověk napsal nebo jak stojí v EXIFu, žádný okamžik v UTC.
 *
 * Hodnoty se převodem nemění: MySQL při `MODIFY` z TIMESTAMP na DATETIME
 * zapíše to, co TIMESTAMP ukazuje v pásmu **sezení** — a to je stejné pásmo,
 * ve kterém je aplikace zapisovala a čte (`config/database.php` pásmo
 * spojení nenastavuje, obojí tedy běží v pásmu serveru). Migrace proto pásmo
 * sezení schválně nemění; kdyby ho nastavila jinak než aplikace, posunula by
 * všechna data pořízení o hodinu či dvě.
 *
 * Index `(gallery_space_id, taken_at)` `MODIFY COLUMN` zachová.
 *
 * Jen MySQL/MariaDB: SQLite typ sloupce nerozlišuje (a přestavba tabulky, na
 * kterou odkazují desítky cizích klíčů, by v testech jen zdržovala),
 * PostgreSQL má `timestamp` s rozsahem 4713 př. n. l. – 294276.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->mysql()) {
            return;
        }

        Schema::table('media_items', function (Blueprint $table) {
            $table->dateTime('taken_at')->nullable()->change();
        });
    }

    /**
     * Zpátky jen tehdy, když se všechno vejde.
     *
     * Datum mimo rozsah TIMESTAMP by MySQL ve STRICT režimu odmítla, bez něj
     * potichu zapsala nulu — a sken z roku 1965 by přišel o datum natrvalo.
     * Radši se návrat odmítne s vysvětlením.
     */
    public function down(): void
    {
        if (! $this->mysql()) {
            return;
        }

        $mimo = DB::table('media_items')
            ->whereNotNull('taken_at')
            ->where(fn ($q) => $q->where('taken_at', '<', '1970-01-02 00:00:00')->orWhere('taken_at', '>', '2038-01-18 00:00:00'))
            ->count();

        if ($mimo > 0) {
            throw new RuntimeException(
                "media_items.taken_at: {$mimo} fotek má datum mimo rozsah TIMESTAMP (1970–2038). "
                .'Návrat na TIMESTAMP by jejich datum pořízení zničil — nejdřív je opravte ručně.'
            );
        }

        Schema::table('media_items', function (Blueprint $table) {
            $table->timestamp('taken_at')->nullable()->change();
        });
    }

    private function mysql(): bool
    {
        return Schema::hasTable('media_items') && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
