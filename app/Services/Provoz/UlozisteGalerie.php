<?php

namespace App\Services\Provoz;

use App\Jobs\ObnovKvotuDisku;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Services\Billing\EntitlementService;
use App\Services\Storage\DriveConnectionResolver;
use App\Support\SpaceContext;
use Illuminate\Support\Facades\Schema;

/**
 * Čísla pro postranní panel: kolik místa je zabráno a co ještě není v cloudu.
 *
 * Panel měl „57 %", „114,5 GB ze 200 GB" a „3 originály čekají" napsané přímo
 * v designovém souboru. Bylo to vidět na každé obrazovce, takže se ta tři čísla
 * četla nejčastěji ze všeho — a přitom nikdy neplatila.
 *
 * **Když je připojený Google Disk, počítá se podle něj.** Je to totiž jeho místo,
 * které dojde: tarif galerie hlídá, co si dvojice smí uložit, ale zaplnit se může
 * dřív účet na Disku, a to by z čísel podle tarifu nikdo nepoznal.
 */
class UlozisteGalerie
{
    /** Jak dlouho se kvóta z Disku považuje za čerstvou. */
    private const CERSTVOST_MINUT = 30;

    public function __construct(
        private readonly EntitlementService $tarify,
        private readonly DriveConnectionResolver $disky,
    ) {}

    /** @return array<string, mixed> */
    public function panel(GallerySpace $prostor): array
    {
        $disk = $this->disk($prostor);

        return $disk !== null
            ? $this->zDisku($prostor, $disk)
            : $this->zTarifu($prostor);
    }

    /** @return array<string, mixed> */
    private function zDisku(GallerySpace $prostor, StorageConnection $disk): array
    {
        $celkem = (int) $disk->quota_total;
        $zabrano = (int) $disk->quota_used;

        // Účet bez limitu (firemní Workspace) nemá co zaplnit — pruh by u něj
        // byl vždycky na nule a nic by neříkal, takže se ukáže jen objem.
        $bezLimitu = $celkem <= 0;
        $procent = $bezLimitu ? 0 : (int) round($zabrano / $celkem * 100);

        return [
            'zdroj' => 'disk',
            'ucet' => $disk->account_email,
            'pct' => $procent.' %',
            'label' => $bezLimitu
                ? $this->gb($zabrano).' na Google Disku'
                : $this->gb($zabrano).' z '.$this->gb($celkem).' na Google Disku',
            'usedBytes' => $zabrano,
            'limitBytes' => $bezLimitu ? null : $celkem,
        ] + $this->ceka($prostor, true);
    }

    /** @return array<string, mixed> */
    private function zTarifu(GallerySpace $prostor): array
    {
        $vyuziti = $this->tarify->storageUsage($prostor);
        $zabrano = (int) ($vyuziti['used_bytes'] ?? 0);

        /*
         * Limit se počítá z megabajtů tarifu, ne z `limit_bytes`.
         *
         * Ten násobí 1024×1024, takže tarif „25 GB" by v panelu vyšel na 24,4 GB —
         * a v záložce Tarify by přitom stálo 25. Dvě různá čísla pro totéž místo
         * jsou horší než jedno nepřesné, a desítkové gigabajty jsou navíc ty,
         * které ukazuje i Google Disk.
         */
        $limitMb = $vyuziti['limit_mb'] ?? null;
        $celkem = $limitMb === null ? null : (int) ($limitMb * 1_000_000);

        $procent = $celkem ? (int) round($zabrano / $celkem * 100) : 0;

        return [
            'zdroj' => 'tarif',
            'ucet' => null,
            'pct' => $procent.' %',
            'label' => $celkem
                ? $this->gb($zabrano).' z '.$this->gb($celkem)
                : $this->gb($zabrano).' uloženo',
            'usedBytes' => $zabrano,
            'limitBytes' => $celkem,
        ] + $this->ceka($prostor, false);
    }

    /**
     * Co ještě není ve druhé kopii.
     *
     * Bez připojeného Disku se kopírovat nemá kam, takže se místo počtu čekajících
     * souborů řekne rovnou to — mlčet by znamenalo tvrdit, že je všechno zálohované.
     *
     * @return array{sync: string, syncIcon: string, syncTon: string}
     */
    private function ceka(GallerySpace $prostor, bool $maDisk): array
    {
        if (! $maDisk) {
            return [
                'sync' => 'Druhá kopie není nastavená',
                'syncIcon' => 'ph-cloud-slash',
                'syncTon' => 'warn',
            ];
        }

        $ceka = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->where('storage_status', 'local_only')
            ->count();

        return $ceka === 0
            ? ['sync' => 'Vše je ve dvou kopiích', 'syncIcon' => 'ph-cloud-check', 'syncTon' => 'ok']
            : [
                'sync' => $ceka.' '.$this->sklonuj($ceka, 'originál čeká', 'originály čekají', 'originálů čeká'),
                'syncIcon' => 'ph-cloud-arrow-up',
                'syncTon' => 'warn',
            ];
    }

    /**
     * Připojený Disk — a zároveň se postará, aby čísla nezestárla.
     *
     * Kvótu z Google Disku dosud nikdo neobnovoval: zapsala se při připojení účtu
     * a od té chvíle ukazovala stav toho dne. Obnova jde do fronty, aby se na ni
     * nečekalo při vykreslení stránky.
     */
    private function disk(GallerySpace $prostor): ?StorageConnection
    {
        if (! Schema::hasTable('storage_connections')) {
            return null;
        }

        $disk = $this->disky->forSpace($prostor->id);

        if ($disk === null) {
            return null;
        }

        $stara = $disk->quota_refreshed_at === null
            || $disk->quota_refreshed_at->lt(now()->subMinutes(self::CERSTVOST_MINUT));

        if ($stara) {
            ObnovKvotuDisku::dispatch($disk->id);
        }

        // Účet bez zapsané kvóty ještě nic neříká — do prvního obnovení se počítá
        // podle tarifu, což je horší odhad, ale pravdivý.
        return $disk->quota_total === null && $disk->quota_used === null ? null : $disk;
    }

    /** Desítkové gigabajty — tak je počítá i Google Disk a záložka Tarify. */
    private function gb(int $bajtu): string
    {
        $gb = $bajtu / 1_000_000_000;

        if ($gb >= 1000) {
            return str_replace('.', ',', (string) round($gb / 1000, 2)).' TB';
        }

        return str_replace('.', ',', (string) round($gb, 1)).' GB';
    }

    private function sklonuj(int $kolik, string $jeden, string $dva, string $pet): string
    {
        return match (true) {
            $kolik === 1 => $jeden,
            $kolik >= 2 && $kolik <= 4 => $dva,
            default => $pet,
        };
    }
}
