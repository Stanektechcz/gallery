<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\MediaItem;
use App\Services\Media\MediaPurger;
use App\Support\SpaceContext;
use Illuminate\Console\Command;

/**
 * Koš po třiceti dnech.
 *
 * Do té doby jde všechno vrátit — teprve pak se originál smaže z disku.
 *
 * Aplikace dosud `purge_after` jen zapisovala a nikdo podle něj neuklízel:
 * smazaná fotka zůstávala na disku i v součtu úložiště navždy, takže dvojice
 * platila za místo, které podle obrazovky uvolnila. Datum úklidu se nastavuje
 * při mazání a `--dny` ho jen dorovná pro řádky, kterým chybí.
 */
class PurgeTrashCommand extends Command
{
    protected $signature = 'gallery:purge-trash {--dny= : Přepíše lhůtu z config/gallery.php} {--nasucho : Jen vypíše, co by se smazalo}';

    protected $description = 'Trvale smaže položky, které jsou v koši déle než nastavená lhůta';

    public function __construct(private readonly MediaPurger $mazani)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dny = (int) ($this->option('dny') ?: config('gallery.trash_retention_days', 30));
        $nasucho = (bool) $this->option('nasucho');
        $hranice = now()->subDays($dny);

        // Řádek bez `purge_after` (starší mazání) se posuzuje podle `trashed_at`,
        // jinak by v koši zůstal navždy.
        $fronta = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->whereNotNull('trashed_at')
            ->where(function ($dotaz) use ($hranice) {
                $dotaz->where('purge_after', '<=', now())
                    ->orWhere(fn ($bez) => $bez->whereNull('purge_after')->where('trashed_at', '<=', $hranice));
            });

        $smazano = 0;
        $bajtu = 0;

        /*
         * `lazyById`, ne `each()`.
         *
         * `each()` stránkuje podle odsazení (offset): jenže tenhle callback
         * řádky mažou, takže druhá stránka začíná tam, kde by bez mazání
         * začínala třetí — polovina fronty se tak přeskočila. `lazyById`
         * stránkuje podle primárního klíče větších řádků, který mazání
         * neposouvá.
         */
        $fronta->with('variants')->lazyById()->each(function (MediaItem $media) use ($nasucho, &$smazano, &$bajtu) {
            if ($nasucho) {
                // Jméno z trezoru ani do výpisu — výstup plánovače končí v logu.
                $this->line('  '.$media->uuid.'  '.($media->is_hidden ? '(trezor)' : $media->original_filename));
                $smazano++;
                $bajtu += (int) $media->size_bytes;

                return;
            }

            // Jméno souboru jen mimo trezor, stejně jako `MazaniFotek::soubor()`:
            // přehled „Dnes" jména z protokolu vypisuje, a jméno trezorové fotky
            // by se tak ukázalo i se zamčeným trezorem.
            AuditLog::record('media.purge', $media, ($media->is_hidden ? [] : [
                'filename' => $media->original_filename,
            ]) + [
                'duvod' => 'lhůta koše',
            ]);

            /*
             * Přes `MediaPurger`, ne vlastní kopií mazání.
             *
             * Tenhle příkaz měl vlastní `smazSoubory()`, které o Google Disku
             * nevědělo — fotka, kterou aplikace hlásila jako trvale smazanou,
             * ležela dál v cizím cloudu. `MediaPurger` je to jedno místo, kam
             * patří všechno mazání souborů; jeho docblock přesně před dvěma
             * kopiemi varuje.
             *
             * Navíc volalo `Storage::disk($varianta->disk)`, jenže zrcadlení
             * zapisuje do `disk` jméno poskytovatele (`dropbox`, `onedrive`),
             * které v `config/filesystems.php` není — výjimka spadla do logu
             * a mazání pokračovalo dál.
             */
            $this->mazani->purge($media);
            // `forceDelete`, ne `delete`: model má soft delete, a měkce smazaný
            // řádek by dál držel místo v součtu i v databázi.
            $media->forceDelete();

            $smazano++;
            $bajtu += (int) $media->size_bytes;
        });

        $sirotku = $nasucho ? 0 : $this->uklidSirotky();

        $this->info($smazano
            ? ($nasucho ? 'Ke smazání: ' : 'Smazáno: ').$smazano.' položek · '.round($bajtu / 1048576, 1).' MB'
            : 'Koš je prázdný.');

        if ($sirotku > 0) {
            $this->info('Uklizeno '.$sirotku.' řádků po dřívějším měkkém mazání.');
        }

        return self::SUCCESS;
    }

    /**
     * Sirotci po dřívějším měkkém mazání.
     *
     * Obě obrazovky koše dřív volaly `->delete()` na modelu se `SoftDeletes`,
     * takže soubory zmizely a řádek zůstal s `deleted_at`. Do koše se nevrátí
     * (dotaz ho nevidí) a tenhle příkaz ho taky míjel, protože měkce smazané
     * řádky z dotazu vypadnou. Zůstávaly tedy navždy a držely místo v součtu.
     */
    private function uklidSirotky(): int
    {
        $sirotci = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->onlyTrashed()
            ->get();

        foreach ($sirotci as $media) {
            // Soubory už nejsou; `purge` je bezpečné volat znovu a postará se
            // i o kopii na Disku, kterou dřívější cesta nechala ležet.
            $this->mazani->purge($media);
            $media->forceDelete();
        }

        return $sirotci->count();
    }
}
