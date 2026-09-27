<?php

namespace App\Jobs\Media;

use App\Models\CloudCopyDeletion;
use App\Models\MediaItem;
use App\Services\Media\KopieTrezoru;
use App\Services\Media\KopieVCloudu;
use App\Support\SpaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Položka odešla do trezoru — její kopie v cloudu mají zmizet.
 *
 * Jen po ověření originálu na serveru (`KopieTrezoru::procNelzeOverit`):
 * kopie v cloudu může být ve skutečnosti jediná. Neověřenou položku úloha
 * nechá být a zapíše to do logu (bez jména souboru); ukáže ji doktor
 * i `gallery:trezor-z-cloudu`.
 *
 * Záznamy o kopiích, vynulování `drive_file_id` a smazání variant `cloud_copy`
 * jdou v jedné transakci se zámkem řádku — zrcadlení a dokončení nahrávání
 * na Disk zamykají tentýž řádek, takže se kopie nemůže „vrátit" mezi
 * zapsáním záznamu a vynulováním odkazu. Samotné mazání v cloudu dělá až
 * `RemoveCloudCopy` po potvrzení transakce.
 */
class OdeberKopieVTrezoru implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var list<int> Výjimka (databáze) se zkouší s odstupem. */
    public array $backoff = [60, 300, 900];

    /** Po kolika sekundách se zeptat znovu, když na Disk právě běží nahrávání. */
    private const NAHRAVANI_ZNOVU_ZA = 600;

    public function __construct(public readonly int $mediaId)
    {
        $this->onQueue('drive');
    }

    /**
     * Rozběhnuté nahrávání se čeká nejdéle tak dlouho, než se z něj stane
     * zaseknuté (`MediaItem::nahravaNaDisk`) — pak už nikoho nezdrží.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(MediaItem::NAHRAVANI_NA_DISK_ZASEKNUTE_PO_HODINACH + 1);
    }

    public function handle(KopieTrezoru $trezor, KopieVCloudu $kopie): void
    {
        // Nejdřív bez zámku: ověření originálu čte celý soubor kvůli otisku
        // a u dlouhého videa by zámek řádku držel zbytečně dlouho.
        $vysledek = $this->stav($this->nacti(), $trezor);

        if ($vysledek === null) {
            $vysledek = DB::transaction(function () use ($kopie) {
                $media = $this->nacti(zamknout: true);

                // Znovu pod zámkem — mezitím mohla položka z trezoru vyjít
                // nebo se rozběhnout nahrávání.
                if (! $media || ! $media->is_hidden || ! $this->maKopii($media)) {
                    return 'nic';
                }

                if ($media->nahravaNaDisk()) {
                    return 'nahrava';
                }

                return $this->odeber($media, $kopie);
            });
        }

        if ($vysledek === 'nahrava') {
            $this->release(self::NAHRAVANI_ZNOVU_ZA);

            return;
        }

        if (! in_array($vysledek, ['nic', 'hotovo'], true)) {
            Log::warning('Kopie položky v trezoru zůstávají v cloudu — originál na serveru nejde ověřit', [
                'media_id' => $this->mediaId,
                'duvod' => $vysledek,
            ]);
        }
    }

    private function nacti(bool $zamknout = false): ?MediaItem
    {
        return MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->withTrashed()
            ->when($zamknout, fn ($q) => $q->lockForUpdate())
            ->find($this->mediaId);
    }

    /** `null` = odebrat, jinak proč ne: `nic`, `nahrava` nebo důvod z `KopieTrezoru`. */
    private function stav(?MediaItem $media, KopieTrezoru $trezor): ?string
    {
        // Mezitím vyjmutá (nebo smazaná) položka se nechává být.
        if (! $media || ! $media->is_hidden || ! $this->maKopii($media)) {
            return 'nic';
        }

        // Nahrávání na Disk právě běží. Úloha částí ho u položky v trezoru
        // zastaví, a kdyby soubor na Disku přece jen dokončila, zapíše jeho
        // smazání sama — tady stačí počkat, až stav `uploading` zmizí.
        if ($media->nahravaNaDisk()) {
            return 'nahrava';
        }

        return $trezor->procNelzeOverit($media);
    }

    private function maKopii(MediaItem $media): bool
    {
        return (bool) $media->drive_file_id
            || $media->variants()->where('type', 'cloud_copy')->where('disk', '!=', 'public')->exists();
    }

    private function odeber(MediaItem $media, KopieVCloudu $kopie): string
    {
        $ids = $kopie->zaznamenej($media, CloudCopyDeletion::DUVOD_TREZOR);

        $media->variants()->where('type', 'cloud_copy')->where('disk', '!=', 'public')->delete();
        $media->forceFill([
            'drive_file_id' => null,
            'drive_parent_folder_id' => null,
            'storage_status' => 'local_only',
        ])->save();

        // Až po potvrzení transakce — uvnitř jí jen zapíše obsluhu.
        $kopie->zaradPoPotvrzeni($ids);

        return 'hotovo';
    }
}
