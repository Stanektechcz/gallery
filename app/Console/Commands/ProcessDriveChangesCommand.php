<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zpracuje změny z Disku a založí rozpory, které z nich plynou.
 *
 * Webhook z Google Disku ukládal změny do `drive_changes` se stavem `pending`
 * — a nic je nikdy nezpracovalo. Tabulka rostla, obrazovka „Rozpory mezi
 * zařízeními" četla `drive_conflicts`, do které nikdo nepsal, a kreslila
 * ukázku. Fotka smazaná na Disku tak v aplikaci zůstala jako platná.
 *
 * Rozpor tady nevzniká zápisem člověka, ale synchronizací — proto to není
 * formulář, ale příkaz v plánovači.
 */
class ProcessDriveChangesCommand extends Command
{
    protected $signature = 'gallery:process-drive-changes {--limit=500 : Kolik čekajících změn zpracovat najednou}';

    protected $description = 'Projde čekající změny z Disku a založí rozpory, které se z nich poznají.';

    public function handle(): int
    {
        foreach (['drive_changes', 'drive_conflicts', 'media_items'] as $tabulka) {
            if (! Schema::hasTable($tabulka)) {
                $this->error("Tabulka {$tabulka} v databázi není.");

                return self::FAILURE;
            }
        }

        $zmeny = DB::table('drive_changes')
            ->where('processed_status', 'pending')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($zmeny->isEmpty()) {
            $this->info('Žádné čekající změny.');

            return self::SUCCESS;
        }

        $rozpory = 0;

        foreach ($zmeny as $zmena) {
            $polozka = $zmena->file_id === null ? null : DB::table('media_items')
                ->where('drive_file_id', $zmena->file_id)
                ->first(['id', 'gallery_space_id', 'original_filename', 'trashed_at', 'updated_at']);

            // Změna souboru, který aplikace nezná: nemá s čím být v rozporu.
            // Bývá to cizí složka na tomtéž Disku a je to normální stav.
            if ($polozka === null) {
                $this->hotovo($zmena->id, 'ignored');

                continue;
            }

            $druh = $this->druh($zmena, $polozka);

            if ($druh === null) {
                $this->hotovo($zmena->id, 'processed');

                continue;
            }

            $rozpory += $this->zaloz($zmena, $polozka, $druh) ? 1 : 0;
            $this->hotovo($zmena->id, 'conflict');
        }

        $this->info($zmeny->count().' změn zpracováno, '.$rozpory.' rozporů založeno.');

        return self::SUCCESS;
    }

    /**
     * Co je na té změně v rozporu — nebo nic.
     *
     * Rozpor je jen tam, kde se obě strany rozešly. Soubor smazaný na Disku,
     * který dvojice smazala i v aplikaci, rozpor není: shodly se.
     */
    private function druh(object $zmena, object $polozka): ?string
    {
        $smazano = (bool) $zmena->removed || (bool) $zmena->trashed;

        if ($smazano) {
            return $polozka->trashed_at === null ? 'deleted_on_drive' : null;
        }

        if ($polozka->trashed_at !== null) {
            return 'deleted_in_app';
        }

        $jmenoNaDisku = trim((string) ($zmena->file_name ?? ''));
        $jmenoVAplikaci = trim((string) ($polozka->original_filename ?? ''));

        if ($jmenoNaDisku !== '' && $jmenoVAplikaci !== '' && $jmenoNaDisku !== $jmenoVAplikaci) {
            return 'renamed_on_drive';
        }

        return null;
    }

    /**
     * Založí rozpor — jednou. Druhý webhook o téže věci nemá dvojici hlásit
     * totéž podruhé.
     */
    private function zaloz(object $zmena, object $polozka, string $druh): bool
    {
        $uz = DB::table('drive_conflicts')
            ->where('storage_connection_id', $zmena->storage_connection_id)
            ->where('entity_type', 'media_item')
            ->where('entity_id', $polozka->id)
            ->where('conflict_type', $druh)
            ->whereNull('resolved_at')
            ->exists();

        if ($uz) {
            return false;
        }

        DB::table('drive_conflicts')->insert([
            'storage_connection_id' => $zmena->storage_connection_id,
            'entity_type' => 'media_item',
            'entity_id' => $polozka->id,
            'drive_file_id' => $zmena->file_id,
            'conflict_type' => $druh,
            'app_state' => json_encode($this->stavAplikace($polozka, $druh), JSON_UNESCAPED_UNICODE),
            'drive_state' => json_encode($this->stavDisku($zmena, $druh), JSON_UNESCAPED_UNICODE),
            'resolution' => 'unresolved',
            'detected_at' => $zmena->change_time ? CarbonImmutable::parse($zmena->change_time) : CarbonImmutable::now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    /**
     * Co o té věci říká aplikace.
     *
     * `caption` je to, co obrazovka postaví proti druhé straně — u smazání
     * tedy stav, ne název souboru. Dvakrát „IMG_2418.jpg" vedle sebe říká
     * jen to, že jde o týž soubor, což dvojice ví.
     *
     * @return array<string, mixed>
     */
    private function stavAplikace(object $polozka, string $druh): array
    {
        $jmeno = (string) ($polozka->original_filename ?? '');

        return [
            'caption' => match ($druh) {
                'deleted_on_drive' => 'zůstala v knihovně',
                'deleted_in_app' => 'je v koši',
                default => $jmeno,
            },
            'name' => $jmeno,
            'trashed' => $polozka->trashed_at !== null,
            'at' => (string) $polozka->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    private function stavDisku(object $zmena, string $druh): array
    {
        $jmeno = (string) ($zmena->file_name ?? '');

        return [
            'caption' => match ($druh) {
                'deleted_on_drive' => 'smazáno na Disku',
                'deleted_in_app' => 'na Disku zůstal',
                default => $jmeno,
            },
            'name' => $jmeno,
            'trashed' => (bool) $zmena->removed || (bool) $zmena->trashed,
            'at' => (string) ($zmena->change_time ?? ''),
        ];
    }

    private function hotovo(int $id, string $stav): void
    {
        DB::table('drive_changes')->where('id', $id)->update([
            'processed_status' => $stav,
            'updated_at' => now(),
        ]);
    }
}
