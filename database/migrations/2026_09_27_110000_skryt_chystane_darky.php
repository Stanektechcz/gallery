<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chystané dárky zapsané z prototypu dostanou `visibility = private`.
 *
 * `DarkyVeStavu` psal u nákupu jen `private_to_user_id`, sloupec
 * `visibility` zůstal na výchozím „shared". Kalendář dárků
 * (`/api/v1/calendar/gifts`), rozpočty dárků i koordinace dvojice ale
 * soukromí čtou právě z `visibility` — druhý tam dárek pro sebe viděl.
 *
 * Řádek s vlastníkem soukromí je soukromý vždycky; zpětně se srovná jen
 * tenhle rozpor. Opačný směr (soukromý bez vlastníka) se nemění: takový
 * dárek nevidí nikdo, ale komu patří, se z dat říct nedá.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gift_ideas')
            || ! Schema::hasColumn('gift_ideas', 'visibility')
            || ! Schema::hasColumn('gift_ideas', 'private_to_user_id')) {
            return;
        }

        DB::table('gift_ideas')
            ->whereNotNull('private_to_user_id')
            ->where('visibility', '!=', 'private')
            ->update(['visibility' => 'private']);
    }

    /**
     * Zpět se nevrací: zveřejnit schovaný dárek by byla přesně ta chyba,
     * kterou migrace opravuje.
     */
    public function down(): void {}
};
