<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jména fotek z trezoru pryč ze starších záznamů protokolu.
 *
 * Přesun do trezoru a ven (`vault.add`, `vault.remove`) zapisoval do protokolu
 * i jméno souboru — a protokol vidí správa i s trezorem zamčeným. Totéž nese
 * záznam o nahrání fotky, kterou někdo do trezoru přesunul až potom. Od kola
 * 44 se jméno u trezoru nezapisuje a přehled „Dnes" ho u položek v trezoru
 * nevypisuje; tohle dočistí řádky, které vznikly dřív.
 *
 * Záznamy o položkách, které už neexistují (trvalé smazání), zůstávají, jak
 * jsou: jestli byla položka v trezoru, se z nich poznat nedá.
 *
 * JSON se čte a skládá v PHP, ne funkcemi databáze — `JSON_REMOVE` SQLite
 * v testech nezná a stejná migrace musí proběhnout na obou. Opakované
 * spuštění nic nemění.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const AKCE = ['vault.add', 'vault.remove'];

    /**
     * `AuditLog::record` ukládá krátké jméno třídy; starší zápisy a testy
     * i plné.
     *
     * @var list<string>
     */
    private const MEDIA = ['MediaItem', 'App\Models\MediaItem'];

    public function up(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        $this->odeberJmena(DB::table('audit_logs')->whereIn('action', self::AKCE));

        if (Schema::hasTable('media_items')) {
            $this->odeberJmena(DB::table('audit_logs')
                ->whereIn('subject_type', self::MEDIA)
                ->whereIn('subject_id', DB::table('media_items')->select('id')->where('is_hidden', true)));
        }
    }

    /** Vrátit nejde — smazaná jména nikde nejsou, a tak to má být. */
    public function down(): void {}

    private function odeberJmena(Builder $dotaz): void
    {
        $dotaz->whereNotNull('payload')->chunkById(500, function ($radky) {
            foreach ($radky as $radek) {
                $data = json_decode((string) $radek->payload, true);

                if (! is_array($data) || ! array_key_exists('filename', $data)) {
                    continue;
                }

                unset($data['filename']);

                DB::table('audit_logs')->where('id', $radek->id)->update([
                    'payload' => $data === [] ? null : json_encode($data, JSON_UNESCAPED_UNICODE),
                ]);
            }
        });
    }
};
