<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Oprava nálezů duplicit, do kterých staré hledání zapsalo fotky jiných dvojic.
 *
 * Týdenní `gallery:scan-duplicates` porovnával celou databázi naráz. Když dvě
 * dvojice měly tutéž fotku, vznikl jeden nález v prostoru té první — i s fotkou
 * té druhé. „Sloučit" (`UklidVeStavu::sluc()`) pak poslalo do koše všechno
 * kromě vítěze, cizí fotku taky, a zapsalo `trashed_at` i `resolved_at` týmž
 * `now()`. Jiný záznam o tom, co sloučení vyhodilo, neexistuje.
 *
 * Co se tu děje, pro každý nález s cizí fotkou:
 *  1. U sloučeného nálezu se cizí fotky vyhozené v okamžiku sloučení (± pár
 *     minut) vrátí z koše. Co si druhá dvojice vyhodila sama jindy, zůstane.
 *  2. Pokud si dvojice za vítěze vybrala cizí fotku, přišla sloučením o všechny
 *     vlastní kopie — ty se vrátí taky a nález se otevře, ať rozhodne znovu.
 *  3. Cizí fotky se z nálezu odpojí; nález, ve kterém pak nezbudou aspoň dvě
 *     položky, se zruší. Příští týdenní běh najde duplicity znovu, už po prostorech.
 *
 * Nic se nemaže kromě řádků nálezů; fotky zůstávají. Opakované spuštění nic
 * dalšího nezmění — smíšený nález po prvním průchodu už neexistuje.
 */
return new class extends Migration
{
    /** Jak daleko od `resolved_at` smí ležet `trashed_at`, aby to bylo totéž sloučení. */
    private const OKNO_MINUT = 5;

    public function up(): void
    {
        if (! Schema::hasTable('duplicate_groups') || ! Schema::hasTable('duplicate_group_items')) {
            return;
        }

        $smisene = DB::table('duplicate_groups as g')
            ->join('duplicate_group_items as p', 'p.duplicate_group_id', '=', 'g.id')
            ->join('media_items as m', 'm.id', '=', 'p.media_item_id')
            ->whereColumn('m.gallery_space_id', '!=', 'g.gallery_space_id')
            ->distinct()
            ->orderBy('g.id')
            ->pluck('g.id');

        foreach ($smisene as $id) {
            DB::transaction(fn () => $this->oprav((int) $id));
        }
    }

    public function down(): void
    {
        // Vrácené fotky ani odpojené cizí položky se zpátky nevracejí —
        // to by znovu otevřelo cestu k cizím fotkám.
    }

    private function oprav(int $id): void
    {
        $skupina = DB::table('duplicate_groups')->where('id', $id)->first();

        if ($skupina === null) {
            return;
        }

        $polozky = DB::table('duplicate_group_items as p')
            ->join('media_items as m', 'm.id', '=', 'p.media_item_id')
            ->where('p.duplicate_group_id', $id)
            ->get(['p.id as vazba', 'p.is_kept', 'm.id', 'm.gallery_space_id', 'm.trashed_at']);

        [$vlastni, $cizi] = $polozky->partition(
            fn (object $p) => (int) $p->gallery_space_id === (int) $skupina->gallery_space_id,
        );

        if ($skupina->resolution === 'merged' && $skupina->resolved_at !== null) {
            $slouceno = Carbon::parse($skupina->resolved_at);
            $od = $slouceno->copy()->subMinutes(self::OKNO_MINUT);
            $do = $slouceno->copy()->addMinutes(self::OKNO_MINUT);

            $this->vratZKose($cizi->where('is_kept', false), $od, $do);

            // Vítězem byla cizí fotka: vlastní kopie šly do koše všechny.
            if ($cizi->contains(fn (object $p) => (bool) $p->is_kept)) {
                $this->vratZKose($vlastni, $od, $do);

                DB::table('duplicate_group_items')
                    ->where('duplicate_group_id', $id)
                    ->update(['is_kept' => false, 'updated_at' => now()]);

                DB::table('duplicate_groups')
                    ->where('id', $id)
                    ->update(['resolution' => 'unresolved', 'resolved_at' => null, 'updated_at' => now()]);
            }
        }

        DB::table('duplicate_group_items')->whereIn('id', $cizi->pluck('vazba'))->delete();

        if ($vlastni->count() < 2) {
            // Položky nálezu odejdou s ním (cascade); fotky samotné zůstávají.
            DB::table('duplicate_group_items')->where('duplicate_group_id', $id)->delete();
            DB::table('duplicate_groups')->where('id', $id)->delete();
        }
    }

    /** @param  Collection<int, object>  $polozky */
    private function vratZKose($polozky, Carbon $od, Carbon $do): void
    {
        $ids = $polozky->whereNotNull('trashed_at')->pluck('id')->all();

        if ($ids === []) {
            return;
        }

        DB::table('media_items')
            ->whereIn('id', $ids)
            ->whereBetween('trashed_at', [$od->format('Y-m-d H:i:s'), $do->format('Y-m-d H:i:s')])
            ->update(['trashed_at' => null, 'purge_after' => null, 'updated_at' => now()]);
    }
};
