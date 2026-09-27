<?php

namespace App\Services\Media;

use App\Jobs\Media\InitiateDriveResumableUploadJob;
use App\Jobs\Media\OdeberKopieVTrezoru;
use App\Jobs\MirrorMediaToCloud;
use App\Models\MediaItem;
use App\Services\Storage\DriveConnectionResolver;
use App\Support\SpaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Trezor a cloud: „Fotky z trezoru nejdou na cloud" (rozhodnutí 27. 9. 2026).
 *
 * Položka, která vejde do trezoru, přijde o kopie na Google Disku, v Dropboxu,
 * OneDrivu i WebDAV. Položka, která z trezoru vyjde, se začne zrcadlit znovu.
 *
 * **Cloud je zrcadlo, ale ne vždycky zbytečné.** Originál leží na serveru
 * (disk `public`), jenže obsluha souborů i `--recover` u náhledů sáhnou pro
 * originál na Disk, když tu chybí — kopie v cloudu tak může být ve skutečnosti
 * jediná. Proto se kopie maže **jen po ověření místního originálu** (viz
 * `procNelzeOverit`). Neověřenou položku kopie drží dál; ukáže ji
 * `gallery:doctor` a `gallery:trezor-z-cloudu`.
 */
class KopieTrezoru
{
    /** Proč kopii nejde smazat — klíče pro výpis v příkazu. */
    public const BEZ_ORIGINALU = 'bez_originalu';

    public const MIMO_SERVER = 'mimo_server';

    public const CHYBI = 'chybi';

    public const VELIKOST = 'velikost';

    public const OTISK = 'otisk';

    /** Disky, které leží na tomhle serveru. */
    private const MISTNI_DISKY = ['public', 'local'];

    /** @var array<string, string> Důvody česky — do výpisu příkazu i do záznamu mazání. */
    public const POPISY = [
        self::BEZ_ORIGINALU => 'originál nemá záznam',
        self::MIMO_SERVER => 'originál neleží na disku serveru',
        self::CHYBI => 'soubor originálu na serveru chybí',
        self::VELIKOST => 'velikost originálu nesedí (nebo není známá)',
        self::OTISK => 'otisk SHA-256 originálu nesedí',
    ];

    public static function popis(string $duvod): string
    {
        return self::POPISY[$duvod] ?? $duvod;
    }

    public function __construct(private readonly DriveConnectionResolver $disky) {}

    /**
     * Položky právě vložené do trezoru — kopie v cloudu půjdou pryč.
     *
     * Do fronty, ne tady: ověření originálu čte celý soubor kvůli otisku a na
     * to nemá čekat kliknutí. Chybu zařazení jen zapíše; `gallery:doctor`
     * zbylou kopii ukáže a `gallery:trezor-z-cloudu --provest` ji dočistí.
     *
     * Až po potvrzení transakce (zápis stavu běží v jedné): worker by jinak
     * mohl položku přečíst ještě mimo trezor a nic neudělat.
     *
     * @param  array<int|string>  $ids  id položek (`media_items.id`)
     */
    public function vlozeno(array $ids): void
    {
        $ids = $this->jenCisla($ids);

        if ($ids !== []) {
            DB::afterCommit(fn () => $this->zaradOdebrani($ids));
        }
    }

    /** @param  list<int>  $ids */
    private function zaradOdebrani(array $ids): void
    {
        foreach ($ids as $id) {
            try {
                OdeberKopieVTrezoru::dispatch($id);
            } catch (\Throwable $e) {
                Log::warning('Odebrání kopií z cloudu po přesunu do trezoru se nepodařilo zařadit', [
                    'media_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Položky právě vyjmuté z trezoru — zrcadlení se rozběhne znovu.
     *
     * Stejné dvě cesty jako po nahrání: `MirrorMediaToCloud` pro cloud prostoru
     * (a Disk s `gallery_space_id`) a přímo nahrávání na Disk pro účet, který
     * najde jen `DriveConnectionResolver` (Disk připojený přes Google bez
     * prostoru). Dvojí zařazení na Disk hlídá jedinečnost
     * `InitiateDriveResumableUploadJob`.
     *
     * Také až po potvrzení transakce — ze stejného důvodu jako `vlozeno`.
     *
     * @param  array<int|string>  $ids
     */
    public function vyjmuto(array $ids): void
    {
        $ids = $this->jenCisla($ids);

        if ($ids !== []) {
            DB::afterCommit(fn () => $this->zaradZrcadleni($ids));
        }
    }

    /** @param  list<int>  $ids */
    private function zaradZrcadleni(array $ids): void
    {
        foreach ($ids as $id) {
            $media = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)->smiDoCloudu()->find($id);

            if (! $media) {
                continue;
            }

            try {
                MirrorMediaToCloud::dispatch($media->id);

                if (! $media->drive_file_id && $this->disky->forMedia($media) !== null) {
                    InitiateDriveResumableUploadJob::dispatch($media->id)->onQueue('drive');
                }
            } catch (\Throwable $e) {
                // Záloha se dožene nočním `gallery:mirror-backlog`.
                Log::warning('Kopii do cloudu po vyjmutí z trezoru se nepodařilo zařadit', [
                    'media_id' => $media->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Je originál na serveru celý? `null` = ano, jinak důvod (konstanta třídy).
     *
     * Ověřuje se varianta `original` na místním disku: soubor existuje, má
     * velikost, kterou si galerie zapsala, a pokud je znám otisk, sedí i ten.
     * Neznámá velikost se nebere jako shoda — raději kopii nechat, než smazat
     * jedinou zálohu kvůli souboru, o kterém nic nevíme.
     */
    public function procNelzeOverit(MediaItem $media): ?string
    {
        $original = $media->variants()->where('type', 'original')->orderBy('id')->first();

        if (! $original || ! $original->path) {
            return self::BEZ_ORIGINALU;
        }

        if (! in_array($original->disk, self::MISTNI_DISKY, true)) {
            return self::MIMO_SERVER;
        }

        try {
            $disk = Storage::disk($original->disk);
            $cesta = $disk->exists($original->path) ? $disk->path($original->path) : null;
        } catch (\Throwable) {
            $cesta = null;
        }

        if ($cesta === null || ! is_file($cesta)) {
            return self::CHYBI;
        }

        $ocekavano = (int) ($original->size_bytes ?: $media->size_bytes ?: 0);
        clearstatcache(true, $cesta);

        if ($ocekavano <= 0 || filesize($cesta) !== $ocekavano) {
            return self::VELIKOST;
        }

        if ($media->sha256 && ! hash_equals(strtolower((string) $media->sha256), (string) hash_file('sha256', $cesta))) {
            return self::OTISK;
        }

        return null;
    }

    /**
     * @param  array<int|string>  $ids
     * @return list<int>
     */
    private function jenCisla(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn (int $id) => $id > 0,
        )));
    }
}
