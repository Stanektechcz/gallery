<?php

use App\Models\CoupleState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archiv alb.
 *
 * „Archivovat album" dřív schovalo album jen v počítači (stav `albArchived`);
 * telefon ho ukazoval dál a Archiv, o kterém mluvila hláška, neexistoval.
 * Archivované album zůstává i s fotkami, jen se neukazuje mezi Alby.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('albums') && ! Schema::hasColumn('albums', 'archived_at')) {
            Schema::table('albums', function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable();
            });
        }

        // Co už dvojice „archivovala" v počítači (stav `albArchived`), se archivuje
        // doopravdy — jinak by album zmizelo z počítače, ale ne z telefonu.
        if (! Schema::hasTable('couple_states') || ! Schema::hasTable('albums')) {
            return;
        }

        CoupleState::query()->each(function (CoupleState $stav) {
            $archiv = ($stav->data ?? [])['albArchived'] ?? null;

            if (! is_array($archiv) || $archiv === []) {
                return;
            }

            $uuid = array_keys(array_filter($archiv));

            if ($uuid !== []) {
                DB::table('albums')
                    ->where('gallery_space_id', $stav->couple_id)
                    ->whereIn('uuid', $uuid)
                    ->whereNull('archived_at')
                    ->update(['archived_at' => now()]);
            }

            $stav->zapomen(['albArchived']);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('albums', 'archived_at')) {
            Schema::table('albums', function (Blueprint $table) {
                $table->dropColumn('archived_at');
            });
        }
    }
};
