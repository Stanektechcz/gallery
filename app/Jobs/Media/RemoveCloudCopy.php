<?php

namespace App\Jobs\Media;

use App\Models\CloudCopyDeletion;
use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Services\Media\KopieTrezoru;
use App\Services\Storage\DropboxClient;
use App\Services\Storage\GoogleDriveStorageProvider;
use App\Services\Storage\OneDriveClient;
use App\Services\Storage\WebDavClient;
use App\Support\SpaceContext;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Smaže jednu kopii v cloudu po trvalém smazání položky nebo po jejím přesunu
 * do trezoru (`reason = vault`, viz `OdeberKopieVTrezoru`).
 *
 * Nese jen id záznamu v `cloud_copy_deletions`, ne položku — ta už v databázi
 * není. Výpadek cloudu se zkouší znovu s rostoucím odstupem (služba mívá
 * špatnou hodinu, ne den); po posledním pokusu záznam selže a ukáže ho
 * `gallery:doctor`.
 *
 * Chybějící nebo odpojené spojení se znovu nezkouší: token, který někdo odvolal,
 * se sám neobnoví, a úloha by jen točila frontu. Na jiné spojení se úloha
 * nepřepíná — id souboru na cizím Disku by vrátilo „nenalezeno" a kopie by se
 * vykázala jako smazaná, i když leží dál v původním účtu.
 *
 * Koš, ne okamžité zničení: Disk, Dropbox i OneDrive soubor ještě nějakou dobu
 * drží ve svém koši. Stejně se chová koš v galerii, a omyl po schválení obou
 * se tak dá ještě napravit v cloudu.
 *
 * Trezor je výjimka tam, kde to cloud dovolí: fotka z trezoru nemá zůstat ani
 * v koši cloudu (originál je ověřeně na serveru, `OdeberKopieVTrezoru`). Google
 * Disk maže trvale (`files.delete`). Dropbox a OneDrive ne: trvalé smazání
 * (`permanently_delete`) Dropbox dovoluje jen týmovým účtům Business
 * a OneDrive `permanentDelete` u osobních disků obecně nenabízí — tam kopie
 * skončí v koši cloudu a zmizí po jeho lhůtě. WebDAV koš nemá, maže rovnou.
 */
class RemoveCloudCopy implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    /** @var list<int> Sekundy: minuta, pět minut, čtvrthodina, hodina, tři hodiny. */
    public array $backoff = [60, 300, 900, 3600, 10800];

    /** Stavy spojení, ze kterých se samo nic neobnoví. */
    private const ODPOJENO = [StorageConnection::STATUS_DISCONNECTED, 'revoked'];

    /** Fronta `drive` — tam patří všechna práce s cizím úložištěm. */
    public function __construct(public readonly int $deletionId)
    {
        $this->onQueue('drive');
    }

    /**
     * Zařadí smazání a nikdy nevyhodí výjimku.
     *
     * Na synchronní frontě úloha běží přímo tady, takže výpadek cloudu by jinak
     * shodil volajícího — koš, noční úklid nebo ruční opakování. Záznam zůstane
     * a ukáže ho doktor. Jedno místo pro `MediaPurger` i `gallery:cloud-mazani`.
     */
    public static function zaradBezpecne(int $deletionId): bool
    {
        try {
            Bus::dispatch(new self($deletionId));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Smazání kopie v cloudu se nepodařilo zařadit', [
                'zaznam' => $deletionId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function handle(DropboxClient $dropbox, OneDriveClient $oneDrive, WebDavClient $webDav): void
    {
        $zaznam = CloudCopyDeletion::find($this->deletionId);

        // Hotové nebo vzdané se znovu nezkouší — zařazení mohlo proběhnout dvakrát.
        if (! $zaznam || $zaznam->status !== CloudCopyDeletion::STATUS_PENDING) {
            return;
        }

        $spojeni = $zaznam->storage_connection_id ? StorageConnection::find($zaznam->storage_connection_id) : null;

        if ($zaznam->reason === CloudCopyDeletion::DUVOD_TREZOR && $this->zastavTrezor($zaznam)) {
            return;
        }

        if ($duvod = $this->procNelze($zaznam, $spojeni)) {
            $zaznam->forceFill(['status' => CloudCopyDeletion::STATUS_FAILED, 'last_error' => $duvod])->save();

            return;
        }

        $vysledek = match ($zaznam->provider) {
            'dropbox' => $dropbox->delete($spojeni, $zaznam->remote_ref),
            'onedrive' => $oneDrive->delete($spojeni, $zaznam->remote_ref),
            'webdav' => $webDav->delete($spojeni, $zaznam->remote_ref),
            'google_drive' => $this->smazNaDisku(
                $spojeni,
                $zaznam->remote_ref,
                trvale: $zaznam->reason === CloudCopyDeletion::DUVOD_TREZOR,
            ),
        };

        if ($vysledek['ok']) {
            $zaznam->forceFill([
                'status' => CloudCopyDeletion::STATUS_DONE,
                'attempts' => $zaznam->attempts + 1,
                'last_error' => null,
                'done_at' => now(),
            ])->save();

            return;
        }

        $chyba = mb_substr((string) ($vysledek['error'] ?? 'Smazání v cloudu selhalo.'), 0, 1000);

        $zaznam->forceFill(['attempts' => $zaznam->attempts + 1, 'last_error' => $chyba])->save();

        // Výjimkou, ne tichým návratem: jen tak fronta pokus zopakuje podle `backoff`
        // a po posledním zavolá `failed()`.
        throw new \RuntimeException('Kopii v cloudu se nepodařilo smazat: '.$chyba);
    }

    /** Po posledním pokusu: záznam zůstane jako selhaný a ukáže ho doktor. */
    public function failed(?\Throwable $e): void
    {
        $zaznam = CloudCopyDeletion::find($this->deletionId);

        if (! $zaznam || $zaznam->status !== CloudCopyDeletion::STATUS_PENDING) {
            return;
        }

        $zaznam->forceFill([
            'status' => CloudCopyDeletion::STATUS_FAILED,
            'last_error' => $zaznam->last_error ?: mb_substr((string) $e?->getMessage(), 0, 1000),
        ])->save();
    }

    /** Důvod, proč to nemá smysl zkoušet (ani znovu), nebo `null`. */
    private function procNelze(CloudCopyDeletion $zaznam, ?StorageConnection $spojeni): ?string
    {
        if (! in_array($zaznam->provider, ['dropbox', 'onedrive', 'webdav', 'google_drive'], true)) {
            return 'Neznámý cloud „'.$zaznam->provider.'“ — kopii smažte ručně.';
        }

        if (! $spojeni) {
            return 'Připojení cloudu chybí (odpojeno nebo nikdy nezaznamenáno) — kopii smažte ručně.';
        }

        if ($spojeni->provider !== $zaznam->provider) {
            return 'Připojení patří jinému cloudu — kopii smažte ručně.';
        }

        if ($spojeni->revoked_at !== null || in_array($spojeni->connection_status, self::ODPOJENO, true)) {
            return 'Připojení cloudu je odpojené — po novém připojení kopii smažte ručně.';
        }

        return null;
    }

    /**
     * Pojistky záznamu z trezoru těsně před smazáním. `true` = záznam je
     * vyřízený bez mazání (hotovo, nebo selhal s důvodem).
     *
     * Mezi zapsáním záznamu a smazáním mohou uběhnout hodiny a Disk maže
     * trvale. Proto se znovu ptá:
     *
     *  - Je položka pořád v trezoru? Když ne, kopie zůstává — po vyjmutí se
     *    zrcadlí znovu a nové nahrání může ležet na stejné cestě (Dropbox,
     *    OneDrive i WebDAV ukládají pod `uuid.přípona`). Bez zámku by
     *    smazání mohlo závod se zápisem varianty vyhrát a vzít čerstvou
     *    zálohu. Stará kopie mimo trezor nikomu neublíží.
     *  - Neodkazuje na kopii položka znovu? Pak ji nemazat.
     *  - Je originál na serveru pořád ověřený? Bez něj může být kopie
     *    v cloudu jediná — záznam selže s důvodem a doktor ho ukáže.
     *
     * Položka, která už neexistuje, byla trvale smazaná (po schválení obou):
     * její kopie mazat smíme, stejně jako při `purge`.
     */
    private function zastavTrezor(CloudCopyDeletion $zaznam): bool
    {
        $media = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->withTrashed()
            ->where('uuid', $zaznam->media_uuid)
            ->first();

        if (! $media) {
            return false;
        }

        if (! $media->is_hidden || $this->opetPouzivana($zaznam, $media)) {
            $zaznam->forceFill([
                'status' => CloudCopyDeletion::STATUS_DONE,
                'last_error' => $media->is_hidden
                    ? 'Nesmazáno: položka na kopii znovu odkazuje.'
                    : 'Nesmazáno: položka už není v trezoru — kopie zůstává jako záloha.',
                'done_at' => now(),
            ])->save();

            return true;
        }

        if ($duvod = app(KopieTrezoru::class)->procNelzeOverit($media)) {
            $zaznam->forceFill([
                'status' => CloudCopyDeletion::STATUS_FAILED,
                'last_error' => 'Nesmazáno: originál na serveru nejde ověřit ('.KopieTrezoru::popis($duvod)
                    .') — kopie v cloudu může být jediná. Po opravě: php artisan gallery:cloud-mazani --znovu',
            ])->save();

            return true;
        }

        return false;
    }

    /** Odkazuje položka na tuhle kopii znovu? */
    private function opetPouzivana(CloudCopyDeletion $zaznam, MediaItem $media): bool
    {
        if ($zaznam->provider === 'google_drive') {
            return (string) $media->drive_file_id === $zaznam->remote_ref;
        }

        return $media->variants()
            ->where('type', 'cloud_copy')
            ->where('disk', $zaznam->provider)
            ->where('path', $zaznam->remote_ref)
            ->exists();
    }

    /**
     * Do koše na Disku, stejně jako ostatní cloudy — u trezoru trvale.
     * Přes kontejner, aby šel poskytovatel v testech nahradit (jako v úlohách
     * složek na Disku).
     *
     * @return array{ok: bool, error?: string}
     */
    private function smazNaDisku(StorageConnection $spojeni, string $idSouboru, bool $trvale = false): array
    {
        try {
            $disk = app(GoogleDriveStorageProvider::class, ['connection' => $spojeni]);
            $trvale ? $disk->deletePermanently($idSouboru) : $disk->trash($idSouboru);

            return ['ok' => true];
        } catch (GoogleServiceException $e) {
            // Soubor na Disku už není — smazaný je.
            if ((int) $e->getCode() === 404) {
                return ['ok' => true];
            }

            return ['ok' => false, 'error' => 'Google Disk odpověděl chybou '.$e->getCode().'.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Google Disk: '.$e->getMessage()];
        }
    }
}
