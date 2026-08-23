<?php

namespace App\Console\Commands;

use App\Models\MediaItem;
use App\Models\Tag;
use App\Support\SpaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Doplní štítky, které jdou odvodit z toho, co u fotky už stojí.
 *
 * Ročního období, města ani „o víkendu" se nikdo ručně štítkovat nebude, a přitom je to
 * přesně to, podle čeho člověk za pár let hledá: „ta zimní z Vídně". Data k tomu máme —
 * datum pořízení a poloha — jen z nich nikdo nic nedělal.
 *
 * Štítkuje se jen to, co je jisté. Žádné hádání obsahu fotky: rozpoznat, že na snímku je
 * pes, umíme leda špatně, a špatný štítek je horší než žádný, protože se podle něj hledá.
 *
 * Ručně přidaný štítek se nikdy neodebírá. Tenhle příkaz smí přidávat, ne uklízet po
 * lidech.
 */
class AutoTagCommand extends Command
{
    protected $signature = 'gallery:auto-tag
                            {--space= : Jen tento prostor}
                            {--limit=2000 : Nejvýš tolik položek v jednom běhu}
                            {--apply : Skutečně zapsat; bez toho jen ukáže, co by vzniklo}';

    protected $description = 'Doplní štítky odvozené z data a místa pořízení.';

    public function handle(): int
    {
        $zapsat = (bool) $this->option('apply');

        $media = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->whereNull('trashed_at')
            ->when($this->option('space'), fn ($q, $id) => $q->where('gallery_space_id', $id))
            ->whereNotNull('taken_at')
            ->with('tags:id,name')
            ->limit((int) $this->option('limit'))
            ->get();

        $pridano = 0;

        foreach ($media as $item) {
            $navrhy = $this->tagsFor($item);
            if (! $navrhy) continue;

            $uzMa = $item->tags->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
            $nove = array_values(array_filter($navrhy, fn ($t) => ! in_array(mb_strtolower($t), $uzMa, true)));

            if (! $nove) continue;

            $this->line('  ' . mb_strimwidth($item->original_filename ?? ('#' . $item->id), 0, 32, '…')
                . '  +' . implode(', +', $nove));

            if ($zapsat) {
                foreach ($nove as $jmeno) {
                    // Slug si model nedoplňuje sám a sloupec je povinný, takže se počítá
                    // tady — jinak první nový štítek spadne na omezení databáze.
                    $tag = Tag::firstOrCreate(
                        ['gallery_space_id' => $item->gallery_space_id, 'name' => $jmeno],
                        [
                            'slug' => \Illuminate\Support\Str::slug($jmeno) ?: mb_strtolower($jmeno),
                            'depth' => 0,
                            'created_by' => $item->uploaded_by ?? $item->owner_user_id,
                        ],
                    );

                    // tagged_by zůstává null: štítek přidal systém, ne člověk, a vydávat
                    // ho za ruční práci by zmátlo každého, kdo se ptá, kdo to tam dal.
                    DB::table('media_tag')->insertOrIgnore([
                        'media_item_id' => $item->id,
                        'tag_id' => $tag->id,
                        'tagged_by' => null,
                        'created_at' => now(),
                    ]);
                }
            }

            $pridano += count($nove);
        }

        $this->newLine();
        $this->info($pridano === 0
            ? 'Nic k doplnění.'
            : ($zapsat ? "Přidáno {$pridano} štítků." : "Přidalo by se {$pridano} štítků. Spusťte s --apply."));

        return self::SUCCESS;
    }

    /**
     * Co se dá o snímku říct s jistotou.
     *
     * @return list<string>
     */
    private function tagsFor(MediaItem $item): array
    {
        $tagy = [];
        $kdy = $item->taken_at;

        // Roční období podle měsíce. Prosinec patří k zimě, ne k podzimu, proto se
        // počítá po trojicích od prosince.
        $tagy[] = match ((int) $kdy->month) {
            12, 1, 2 => 'zima',
            3, 4, 5 => 'jaro',
            6, 7, 8 => 'léto',
            default => 'podzim',
        };

        if ($kdy->isWeekend()) $tagy[] = 'víkend';

        // Večerní a noční snímky se hledají jinak než denní. Hranice schválně široká —
        // „večer" je od šesti, ne od astronomického soumraku.
        $hodina = (int) $kdy->hour;
        if ($hodina >= 18 || $hodina < 5) $tagy[] = 'večer';

        // Město, kde to vzniklo. Jen z uloženého názvu místa, ne z geokódování — dotaz
        // na tisíce fotek by trval hodiny a jméno už u nich stojí.
        if (filled($item->location_name)) {
            $mesto = trim(explode(',', (string) $item->location_name)[0]);
            if (mb_strlen($mesto) >= 2 && mb_strlen($mesto) <= 40) $tagy[] = $mesto;
        }

        if (filled($item->location_country)) $tagy[] = (string) $item->location_country;

        return array_values(array_unique($tagy));
    }
}
