<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Najde (a na požádání smaže) řádky, které do databáze zapsal prototyp z ukázky.
 *
 * Dokud server u prázdného modulu neposílal prázdné seznamy, držela obrazovka
 * ukázková data z `galerie-data.js`. Stačilo pak přidat jeden film nebo úkol
 * a klient poslal celý seznam — i s cizími tituly — a zápis stavu je vložil
 * jako skutečné řádky („Dune: Part Two", „Máma, Olomouc", „Sport nebo
 * procházka"). Příčina je pryč, řádky ale v databázi zůstaly.
 *
 * Za ukázku se považuje jen řádek, jehož název **přesně** odpovídá textu
 * z prototypu a který vznikl **ve stejné vteřině** jako aspoň jeden další
 * takový řádek ve stejné tabulce a prostoru. Přesně tak vypadá dávka ze
 * stavu; člověk, který si sám zapíše „Randíčko", tvoří řádky po jednom.
 * Osamocené shody se jen vypíšou k posouzení a nemažou se nikdy.
 */
class UkazkovaDataCommand extends Command
{
    protected $signature = 'gallery:ukazkova-data
        {--smazat : Smaže řádky, které přišly dávkou z ukázky}
        {--prostor= : Jen jeden prostor (id)}
        {--force : Nesmí se ptát (nasazení bez terminálu)}';

    protected $description = 'Vypíše řádky zapsané z ukázkových dat prototypu; s --smazat je odstraní';

    /** Tabulky, které plní zápis stavu, a sloupec s názvem, který člověk vidí. */
    public const TABULKY = [
        'watch_titles' => 'title',
        'calendar_events' => 'title',
        'couple_story_milestones' => 'title',
        'couple_story_chapters' => 'title',
        'couple_family_contacts' => 'name',
        'wellbeing_tasks' => 'name',
        'wellbeing_attention' => 'name',
        'couple_date_ideas' => 'title',
        'saved_transport_routes' => 'name',
        'shared_todos' => 'title',
        'shared_todo_lists' => 'title',
        'gift_ideas' => 'title',
        'automation_rules' => 'name',
        'time_capsules' => 'title',
        'emergency_access_items' => 'label',
        'paper_backup_rows' => 'label',
        'couple_decisions' => 'title',
        'couple_promises' => 'what',
        'couple_nudges' => 'text',
        'couple_vetoes' => 'text',
        'couple_veto_proposals' => 'text',
        'couple_cooling_purchases' => 'what',
        'couple_disagreement_points' => 'text',
        'house_chores' => 'name',
        'house_dues' => 'what',
        'house_inventory' => 'name',
    ];

    /** Soubory prototypu, ze kterých ukázka pochází. */
    private const ZDROJE = [
        'public/galerie-data.js',
        'resources/galerie/galerie-desktop.dc.html',
        'resources/galerie/galerie-mobil.dc.html',
    ];

    public function handle(): int
    {
        $ukazka = $this->textyUkazky();

        if ($ukazka === []) {
            $this->error('Soubory prototypu nejsou k dispozici — bez nich nejde poznat, co je ukázka.');

            return self::FAILURE;
        }

        $davky = collect();
        $osamocene = collect();

        foreach (self::TABULKY as $tabulka => $sloupec) {
            if (! Schema::hasTable($tabulka) || ! Schema::hasColumns($tabulka, [$sloupec, 'gallery_space_id', 'created_at'])) {
                continue;
            }

            [$vDavce, $samotne] = $this->shody($tabulka, $sloupec, $ukazka);
            $davky = $davky->merge($vDavce);
            $osamocene = $osamocene->merge($samotne);
        }

        $this->vypis('Z ukázky prototypu (přišlo dávkou)', $davky);
        $this->vypis('Jen shoda názvu — k posouzení, nemaže se', $osamocene);

        if ($davky->isEmpty()) {
            $this->info('Žádné řádky z ukázky.');

            return self::SUCCESS;
        }

        if (! $this->option('smazat')) {
            $this->line('Smazat je lze přes --smazat.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Smazat '.$davky->count().' řádků z ukázky?')) {
            $this->line('Nic se nesmazalo.');

            return self::SUCCESS;
        }

        // Navázané řádky (účastníci události, hodnocení filmu) odejdou
        // s nimi — cizí klíče mají `cascade`.
        DB::transaction(function () use ($davky) {
            foreach ($davky->groupBy('tabulka') as $tabulka => $radky) {
                DB::table($tabulka)->whereIn('id', $radky->pluck('id'))->delete();
            }
        });

        $this->info('Smazáno '.$davky->count().' řádků z ukázky.');

        return self::SUCCESS;
    }

    /**
     * Shody v jedné tabulce rozdělené na dávky a osamocené řádky.
     *
     * @param  array<string, true>  $ukazka
     * @return array{0: Collection<int, array>, 1: Collection<int, array>}
     */
    private function shody(string $tabulka, string $sloupec, array $ukazka): array
    {
        $dotaz = DB::table($tabulka)->orderBy('id');

        if ($this->option('prostor') !== null) {
            $dotaz->where('gallery_space_id', (int) $this->option('prostor'));
        }

        $shody = collect();

        foreach ($dotaz->cursor(['id', 'gallery_space_id', $sloupec, 'created_at']) as $radek) {
            $nazev = (string) $radek->$sloupec;

            if (isset($ukazka[$this->klic($nazev)])) {
                $shody->push([
                    'tabulka' => $tabulka,
                    'id' => $radek->id,
                    'prostor' => $radek->gallery_space_id,
                    'nazev' => $nazev,
                    'vznik' => (string) $radek->created_at,
                ]);
            }
        }

        $skupiny = $shody->groupBy(fn (array $r) => $r['prostor'].'|'.substr($r['vznik'], 0, 19));

        return [
            $skupiny->filter(fn (Collection $s) => $s->count() >= 2)->flatten(1)->values(),
            $skupiny->filter(fn (Collection $s) => $s->count() < 2)->flatten(1)->values(),
        ];
    }

    /**
     * Všechny texty z ukázky, normalizované pro porovnání.
     *
     * Bere se každý řetězec v uvozovkách. Krátká slova („Ano", „Byt") by
     * se shodovala s čímkoli, takže mají aspoň čtyři znaky.
     *
     * @return array<string, true>
     */
    private function textyUkazky(): array
    {
        $texty = [];

        foreach (self::ZDROJE as $soubor) {
            $cesta = base_path($soubor);

            if (! is_file($cesta)) {
                continue;
            }

            preg_match_all('/\'((?:[^\'\\\\\n]|\\\\.){4,200})\'|"((?:[^"\\\\\n]|\\\\.){4,200})"/u', (string) file_get_contents($cesta), $m);

            foreach (array_merge($m[1], $m[2]) as $text) {
                $klic = $this->klic(stripslashes($text));

                if (mb_strlen($klic) >= 4) {
                    $texty[$klic] = true;
                }
            }
        }

        return $texty;
    }

    private function klic(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }

    /** @param  Collection<int, array>  $radky */
    private function vypis(string $nadpis, Collection $radky): void
    {
        if ($radky->isEmpty()) {
            return;
        }

        $this->line('');
        $this->line($nadpis.': '.$radky->count());
        $this->table(
            ['Tabulka', 'Id', 'Prostor', 'Název', 'Vznik'],
            $radky->map(fn (array $r) => [$r['tabulka'], $r['id'], $r['prostor'], mb_strimwidth($r['nazev'], 0, 60, '…'), $r['vznik']])->all(),
        );
    }
}
