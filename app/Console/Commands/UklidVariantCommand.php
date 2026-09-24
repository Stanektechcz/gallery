<?php

namespace App\Console\Commands;

use App\Models\MediaVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Drží mezipaměť odvozených variant pod nastaveným limitem.
 *
 * `variant_cache_max_size_gb` a `variant_cache_max_age_days` byly v konfiguraci
 * od začátku a nečetl je nikdo — dvacet gigabajtů byl údaj v souboru, ne limit.
 *
 * Pravidlo je záměrně takové, aby úklid nikdy nepřekvapil:
 *
 *  * **Velikost rozhoduje, stáří jen vybírá.** Dokud se mezipaměť do limitu
 *    vejde, neděje se nic — ani u variant starých roky. Stáří samo o sobě
 *    zmenšeninu nekazí; teprve když dojde místo, je stáří tím nejlepším
 *    vodítkem, co zahodit dřív.
 *  * **Zahodit jde jen to, co nikomu nechybí.** Originál je sama fotka. Náhled
 *    a plakát videa jsou tvář galerie — bez nich je z mřížky prázdno. `edited_*`
 *    je výsledek úpravy dvojice a po jeho smazání by se mlčky vrátil neupravený
 *    snímek. Zbývají zmenšeniny a převod videa: bez nich se pošle originál,
 *    což je pomalejší, ale správné.
 *  * **Bez originálu se nemaže.** Když soubor originálu na disku není, je
 *    zmenšenina poslední kopie. To už není úklid, to je ztráta.
 *
 * Když ani po vyklizení všeho dovoleného limit nestačí, řekne to nahlas —
 * to je informace pro správce, že problém je velikost knihovny, ne mezipaměti.
 */
class UklidVariantCommand extends Command
{
    protected $signature = 'gallery:uklid-variant
        {--nasucho : Jen vypíše, co by zahodil, a nic nesmaže}';

    protected $description = 'Udrží mezipaměť odvozených variant pod limitem z konfigurace';

    /**
     * Typy, jejichž ztráta nic nestojí — jen se místo nich pošle originál.
     *
     * @var array<string, string>
     */
    private const ZAHODITELNE = [
        'small' => 'zmenšenina 800 px',
        'medium' => 'zmenšenina 1600 px',
        'large' => 'zmenšenina 2560 px',
        'video_compat' => 'převod videa pro starší prohlížeče',
    ];

    /** Kolik variant si najednou vyžádat z databáze. */
    private const DAVKA = 500;

    /** Jen místní disky; na cizí cloud se nedá ani zeptat, a ten soubor tam patří. */
    private const MISTNI = ['public', 'local'];

    /** Velikost mezipaměti na začátku běhu — pro hlášku „uvolněno". */
    private int $puvodni = 0;

    public function handle(): int
    {
        $limit = (int) round((float) config('gallery.variant_cache_max_size_gb', 20) * 1024 ** 3);
        $hranice = now()->subDays((int) config('gallery.variant_cache_max_age_days', 90));
        $nasucho = (bool) $this->option('nasucho');

        $velikost = $this->velikostMezipameti();

        $this->line(sprintf('Mezipaměť variant: %s, limit %s.', $this->cislo($velikost), $this->cislo($limit)));

        if ($velikost <= $limit) {
            $this->info('Vejde se — není co uklízet.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Nad limit o %s. Zahazuji od nejstarší, jen co je starší než %s.',
            $this->cislo($velikost - $limit),
            $hranice->diffForHumans(['parts' => 1, 'syntax' => Carbon::DIFF_ABSOLUTE]),
        ));

        [$velikost, $zahozeno] = $this->zahazuj($velikost, $limit, $hranice, $nasucho);

        $this->line(sprintf(
            '%s %d variant, uvolněno %s.',
            $nasucho ? 'Nasucho bych zahodil' : 'Zahozeno',
            $zahozeno,
            $this->cislo($this->puvodni - $velikost),
        ));

        if ($velikost > $limit) {
            $this->warn(sprintf(
                'Mezipaměť se do limitu ani tak nevešla: zbývá %s proti limitu %s. '
                .'Všechno ostatní je originál, náhled nebo úprava — to se nezahazuje. '
                .'Zvedněte GALLERY_VARIANT_CACHE_GB, nebo přidejte místo.',
                $this->cislo($velikost),
                $this->cislo($limit),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Zahazuje od nejstarší, dokud se mezipaměť nevejde.
     *
     * Postupuje po kurzoru `(updated_at, id)` a nikdy se nevrací: dávka po
     * dávce dopředu. Kdyby se četlo pořád od začátku, přeskočené varianty
     * (chybějící originál, cizí disk) by se vracely donekonečna.
     *
     * @return array{int, int} nová velikost a počet zahozených
     */
    private function zahazuj(int $velikost, int $limit, Carbon $hranice, bool $nasucho): array
    {
        $this->puvodni = $velikost;
        $zahozeno = 0;
        $posledniCas = null;
        $posledniId = 0;

        while ($velikost > $limit) {
            $davka = $this->dalsiDavka($hranice, $posledniCas, $posledniId);

            if ($davka->isEmpty()) {
                break;
            }

            $originaly = $this->originaly($davka->pluck('media_item_id')->unique()->all());

            foreach ($davka as $varianta) {
                $posledniCas = $varianta->updated_at;
                $posledniId = $varianta->id;

                if (! $this->smiPryc($varianta, $originaly[$varianta->media_item_id] ?? null)) {
                    continue;
                }

                $velikost -= $this->zahod($varianta, $nasucho);
                $zahozeno++;

                if ($velikost <= $limit) {
                    break 2;
                }
            }
        }

        return [$velikost, $zahozeno];
    }

    /** @return Collection<int, MediaVariant> */
    private function dalsiDavka(Carbon $hranice, ?Carbon $posledniCas, int $posledniId)
    {
        $dotaz = MediaVariant::query()
            ->whereIn('type', array_keys(self::ZAHODITELNE))
            ->where('updated_at', '<', $hranice)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(self::DAVKA);

        if ($posledniCas !== null) {
            $dotaz->where(fn ($kde) => $kde
                ->where('updated_at', '>', $posledniCas)
                ->orWhere(fn ($stejnyCas) => $stejnyCas
                    ->where('updated_at', $posledniCas)
                    ->where('id', '>', $posledniId)));
        }

        return $dotaz->get();
    }

    /**
     * Originály k dávce najednou — jinak by to byl dotaz na každou variantu.
     *
     * @param  list<int>  $mediaIds
     * @return array<int, MediaVariant>
     */
    private function originaly(array $mediaIds): array
    {
        return MediaVariant::query()
            ->whereIn('media_item_id', $mediaIds)
            ->where('type', 'original')
            ->get()
            ->keyBy('media_item_id')
            ->all();
    }

    /** Zmenšenina smí pryč jen tehdy, když ji jde z originálu vyrobit znovu. */
    private function smiPryc(MediaVariant $varianta, ?MediaVariant $original): bool
    {
        if ($original === null) {
            return false;
        }

        $diskVarianty = $varianta->disk ?: 'public';
        $diskOriginalu = $original->disk ?: 'public';

        if (! in_array($diskVarianty, self::MISTNI, true) || ! in_array($diskOriginalu, self::MISTNI, true)) {
            return false;
        }

        return file_exists(Storage::disk($diskOriginalu)->path($original->path));
    }

    /** Smaže variantu i její soubor a vrátí, kolik bajtů to uvolnilo. */
    private function zahod(MediaVariant $varianta, bool $nasucho): int
    {
        $disk = Storage::disk($varianta->disk ?: 'public');
        $bajtu = (int) ($varianta->size_bytes ?: ($disk->exists($varianta->path) ? $disk->size($varianta->path) : 0));

        $this->line(sprintf(
            '  %s %s (%s, %s)',
            $nasucho ? 'zahodil bych' : 'zahazuji',
            $varianta->path,
            self::ZAHODITELNE[$varianta->type],
            $this->cislo($bajtu),
        ));

        if (! $nasucho) {
            $disk->delete($varianta->path);
            $varianta->delete();
        }

        return $bajtu;
    }

    /** Originál se do mezipaměti nepočítá — to je sama fotka, ne mezipaměť. */
    private function velikostMezipameti(): int
    {
        return (int) MediaVariant::query()->where('type', '!=', 'original')->sum('size_bytes');
    }

    private function cislo(int $bajtu): string
    {
        foreach (['B' => 1, 'kB' => 1024, 'MB' => 1024 ** 2, 'GB' => 1024 ** 3] as $jednotka => $delitel) {
            if ($bajtu < $delitel * 1024 || $jednotka === 'GB') {
                return round($bajtu / $delitel, $delitel === 1 ? 0 : 1).' '.$jednotka;
            }
        }

        return $bajtu.' B';
    }
}
