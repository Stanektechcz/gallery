<?php

namespace App\Services\Provoz;

use App\Models\AuditLog;
use App\Models\BillingPlan;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\PersonalAccessToken;
use App\Models\StorageConnection;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Support\SpaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Administrace prostoru ve tvaru, ve kterém ji kreslí prototyp.
 *
 * Prototyp má celou administraci na klientovi ve `GalerieData.ADMIN` — účty,
 * úlohy, klíče, tarify i rizika jsou tam vymyšlené. Tahle služba dodá tytéž klíče
 * ze skutečných dat, takže se v prototypu nemusí měnit ani řádek: mění se zdroj,
 * ne obrazovka.
 *
 * Čísla se počítají **jednou a tady**. Prototyp si zaplněnost úložiště dopočítává
 * na dvou záložkách zvlášť a jeho vlastní komentář varuje, že si jinak budou
 * protiřečit; když obě dostanou tutéž hodnotu, nemají se kde rozejít.
 */
class AdministraceGalerie
{
    /** Role prostoru → role, kterým rozumí prototyp. */
    private const ROLE_VEN = [
        'owner' => 'vlastník',
        'admin' => 'správce',
        'editor' => 'správce',
        'contributor' => 'host',
        'viewer' => 'host',
    ];

    /** A zpátky. Vlastník se takhle nenastavuje — jen předáním. */
    public const ROLE_DOVNITR = [
        'správce' => 'editor',
        'host' => 'viewer',
    ];

    public function __construct(
        private readonly EntitlementService $tarify,
        private readonly PlanovaneUlohy $ulohy,
    ) {}

    /** @return array<string, mixed> */
    public function prehled(GallerySpace $prostor): array
    {
        $vyuziti = $this->tarify->storageUsage($prostor);
        // `storageUsage` počítá jen to, co není v koši — koš se proto sčítá zvlášť
        // a od obsazenosti se už neodečítá.
        $obsazeno = round(($vyuziti['used_bytes'] ?? 0) / 1_073_741_824, 1);
        $mira = $this->mira($prostor);

        return [
            'roles' => ['vlastník', 'správce', 'host'],
            'roleNote' => [
                'vlastník' => 'vidí vše, platí tarif, může odebrat přístup',
                'správce' => 'vidí vše kromě fakturace, může spravovat úlohy a klíče',
                'host' => 'jen sdílené odkazy, nic v nastavení',
            ],
            'users' => $this->ucty($prostor),
            'jobs' => $this->ulohy->seznam()->all(),
            'incidents' => $this->ulohy->incidenty()->all(),
            'keys' => $this->klice($prostor),
            'plans' => $this->tarifySeznam(),
            'plan' => (string) ($this->tarify->plan($prostor)?->id ?? ''),
            'usedGb' => $obsazeno,
            // Čísla pro pruhy rizika. Prototyp je měl napsaná napevno (8,1 / 1,2 / 2,4),
            // takže obrazovka ukazovala cizí gigabajty bez ohledu na skutečnost.
            'trashGb' => $mira['kos'],
            'singleGb' => $mira['jednaKopie'],
            'growthGb' => $mira['rust'],
            'risks' => $this->rizika($prostor, $obsazeno),
            'health' => $this->zdravi($prostor, $obsazeno, $mira),
            'log' => $this->protokol($prostor),
        ];
    }

    /**
     * Gigabajty, na kterých stojí pruhy rizika.
     *
     * @return array{kos: float, jednaKopie: float, rust: float, kosPocet: int, jednaKopiePocet: int}
     */
    private function mira(GallerySpace $prostor): array
    {
        $media = fn () => MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id);

        $kos = (clone $media())->whereNotNull('trashed_at');
        $jednaKopie = (clone $media())->whereNull('trashed_at')->where('storage_status', 'local_only');

        // Růst za posledního půl roku. Prototyp počítá s 2,4 GB měsíčně napevno;
        // předpověď zaplnění z cizího čísla nikomu nic neřekne.
        $mesicu = 6;
        $prirustek = (int) (clone $media())
            ->where('uploaded_at', '>=', now()->subMonths($mesicu))
            ->sum('size_bytes');

        return [
            'kos' => $this->gb((int) (clone $kos)->sum('size_bytes')),
            'kosPocet' => (clone $kos)->count(),
            'jednaKopie' => $this->gb((int) (clone $jednaKopie)->sum('size_bytes')),
            'jednaKopiePocet' => (clone $jednaKopie)->count(),
            'rust' => $this->gb((int) round($prirustek / $mesicu)),
        ];
    }

    /**
     * Zdraví systému.
     *
     * Prototyp tu měl „dostupnost 99,98 %, disk 57 %" jako text v kódu. Skutečná
     * čísla jsou nudnější a užitečnější: kolik místa zbývá na disku, jestli tepe
     * plánovač a kolik úloh čeká nebo selhalo ve frontě.
     *
     * @param  array{kos: float, jednaKopie: float, rust: float, kosPocet: int, jednaKopiePocet: int}  $mira
     * @return array<string, mixed>
     */
    private function zdravi(GallerySpace $prostor, float $obsazeno, array $mira): array
    {
        $tep = SystemSetting::get('scheduler_last_heartbeat');
        $tepKdy = $tep ? \Illuminate\Support\Carbon::parse($tep) : null;
        $planovacZije = $tepKdy !== null && $tepKdy->gt(now()->subMinutes(5));

        $volno = @disk_free_space(storage_path()) ?: 0;
        $celkem = @disk_total_space(storage_path()) ?: 0;
        $diskPct = $celkem > 0 ? (int) round(($celkem - $volno) / $celkem * 100) : 0;

        $ceka = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;
        $selhalo = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;

        $limit = $this->tarify->storageUsage($prostor)['limit_bytes'] ?? null;
        $tarifPct = $limit ? (int) round($obsazeno * 1_073_741_824 / $limit * 100) : 0;

        return [
            'checkedAt' => $tepKdy ? $this->kdy($tepKdy) : 'zatím nikdy',
            'summary' => 'disk '.$diskPct.' %, tarif '.$tarifPct.' %, '
                .($planovacZije ? 'plánovač běží' : 'plánovač neběží'),
            // [popisek, poznámka, procenta, tón: 0 dobré · 1 zlé · 2 nevýrazné]
            'bars' => [
                ['Místo na disku serveru', $this->cislo($volno / 1_073_741_824).' GB volných', $diskPct, $diskPct > 85 ? 1 : 0],
                ['Zaplněnost tarifu', $this->cislo($obsazeno).' GB uloženo', $tarifPct, $tarifPct > 85 ? 1 : 0],
                ['Plánovač', $planovacZije ? 'tep '.$this->kdy($tepKdy) : 'bez tepu — úlohy neběží', $planovacZije ? 100 : 0, $planovacZije ? 0 : 1],
                ['Fronta úloh', $ceka.' čeká · '.$selhalo.' selhalo', $selhalo > 0 ? 100 : min(100, $ceka * 5), $selhalo > 0 ? 1 : 2],
            ],
        ];
    }

    // ——— účty ———

    /** @return list<array<string, mixed>> */
    public function ucty(GallerySpace $prostor): array
    {
        return $prostor->members()->orderBy('gallery_space_user.created_at')->get()
            ->map(function (User $clen) use ($prostor) {
                $role = $clen->id === $prostor->owner_id
                    ? 'vlastník'
                    : (self::ROLE_VEN[$clen->pivot->role] ?? 'host');

                return [
                    'id' => (string) $clen->id,
                    'name' => $clen->name,
                    'mail' => $clen->email,
                    'role' => $role,
                    'last' => $this->naposledy($clen),
                    'state' => $this->stavUctu($clen),
                ];
            })
            ->values()
            ->all();
    }

    private function stavUctu(User $clen): string
    {
        return match (true) {
            ! $clen->is_active => 'bez přístupu',
            $clen->invitation_token !== null && $clen->invitation_accepted_at === null => 'pozvaná',
            default => 'aktivní',
        };
    }

    private function naposledy(User $clen): string
    {
        $kdy = $clen->last_seen_at ?? $clen->last_login_at;

        if ($kdy === null) {
            return 'nikdy';
        }

        return match (true) {
            $kdy->isToday() => 'dnes '.$kdy->format('G:i'),
            $kdy->isYesterday() => 'včera '.$kdy->format('G:i'),
            default => $kdy->format('j. n. Y'),
        };
    }

    // ——— klíče k API ———

    /** @return list<array<string, mixed>> */
    public function klice(GallerySpace $prostor): array
    {
        $lide = $prostor->members()->pluck('users.id');

        return PersonalAccessToken::where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $lide)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PersonalAccessToken $klic) => [
                'id' => (string) $klic->id,
                'name' => $klic->name,
                'suffix' => $klic->suffix ?: '????',
                'made' => $klic->created_at?->format('j. n. Y') ?? '—',
                'used' => $klic->last_used_at
                    ? 'naposledy '.$klic->last_used_at->format('j. n. G:i')
                    : 'zatím nepoužit',
                'scope' => $this->rozsah($klic),
                'state' => match (true) {
                    $klic->zrusen() => 'zrušený',
                    $klic->necinny() => 'nečinný',
                    default => 'aktivní',
                },
            ])
            ->values()
            ->all();
    }

    private function rozsah(PersonalAccessToken $klic): string
    {
        $moznosti = $klic->abilities ?? [];

        return match (true) {
            in_array('*', $moznosti, true) => 'čtení i zápis',
            $moznosti === ['read'] => 'jen čtení',
            $moznosti === [] => 'bez oprávnění',
            default => implode(', ', $moznosti),
        };
    }

    // ——— tarify ———

    /** @return list<array<string, mixed>> */
    public function tarifySeznam(): array
    {
        return BillingPlan::orderBy('sort_order')->get()
            ->map(fn (BillingPlan $tarif) => [
                'id' => (string) $tarif->id,
                'name' => $tarif->name,
                // Bez limitu se v pruhu nedá nic vykreslit; „neomezeno" má prototyp
                // jako velké číslo, ne jako zvláštní případ.
                'gb' => (int) round(($tarif->storage_limit_mb ?? 4_000_000) / 1000),
                'price' => (int) round($tarif->price_monthly ?? 0),
            ])
            ->values()
            ->all();
    }

    // ——— riziko úložiště ———

    /** @return list<array<string, mixed>> */
    public function rizika(GallerySpace $prostor, float $obsazenoGb): array
    {
        $media = fn () => MediaItem::withoutGlobalScope(\App\Support\SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id);

        $jednaKopie = (clone $media())->whereNull('trashed_at')->where('storage_status', 'local_only');
        $jednaKopieGb = round((int) (clone $jednaKopie)->sum('size_bytes') / 1_073_741_824, 1);
        $jednaKopiePocet = (clone $jednaKopie)->count();

        $kos = (clone $media())->whereNotNull('trashed_at');
        $kosGb = round((int) (clone $kos)->sum('size_bytes') / 1_073_741_824, 1);
        $kosPocet = (clone $kos)->count();

        $cloud = Schema::hasTable('storage_connections')
            ? StorageConnection::where('gallery_space_id', $prostor->id)->first()
            : null;

        return [
            [
                'id' => 'r1',
                'label' => 'Originály jen v jedné kopii',
                'note' => $jednaKopiePocet
                    ? $this->popisGb($jednaKopieGb).' · '.$jednaKopiePocet.' souborů jen na tomhle serveru'
                    : 'nic — vše je ve dvou kopiích',
                'fix' => 'Založit druhou kopii',
                'done' => 'Druhá kopie běží — '.$this->popisGb($jednaKopieGb).' se kopíruje do cloudu',
                'hotovo' => $jednaKopiePocet === 0,
            ],
            [
                'id' => 'r2',
                'label' => $kosPocet
                    ? 'Koš drží '.$kosPocet.' položek'
                    : 'Koš je prázdný',
                'note' => $kosPocet
                    ? 'zabírají '.$this->popisGb($kosGb).', smažou se po lhůtě'
                    : 'nic nečeká na smazání',
                'fix' => 'Vysypat koš teď',
                'done' => 'Koš vysypán — '.$this->popisGb($kosGb).' uvolněno',
                'hotovo' => $kosPocet === 0,
            ],
            [
                'id' => 'r3',
                'label' => 'Záloha bez ověřené obnovy',
                'note' => $cloud?->last_successful_request_at
                    ? 'poslední úspěšný přenos '.$cloud->last_successful_request_at->format('j. n. Y')
                    : 'cloud není připojený — druhá kopie nevzniká',
                'fix' => 'Zkusit obnovu',
                'done' => 'Obnova ověřena — záloha je čitelná',
                'hotovo' => false,
            ],
        ];
    }

    // ——— protokol ———

    /** @return list<array<string, mixed>> */
    public function protokol(GallerySpace $prostor, int $kolik = 40): array
    {
        $lide = $prostor->members()->pluck('users.id');

        return AuditLog::with('user:id,name')
            ->where('action', 'like', 'admin.%')
            ->whereIn('user_id', $lide)
            ->latest('created_at')
            ->limit($kolik)
            ->get()
            ->map(fn (AuditLog $zapis) => [
                'when' => $zapis->created_at?->format('j. n. G:i') ?? '',
                // Jméno přihlášeného, ne „systém": administrace bez toho, kdo zásah
                // udělal, je jen seznam změn.
                'who' => $zapis->user?->name ?? 'systém',
                'what' => (string) ($zapis->payload['popis'] ?? $zapis->action),
            ])
            ->values()
            ->all();
    }

    private function gb(int $bajtu): float
    {
        return round($bajtu / 1_073_741_824, 1);
    }

    /** Číslo česky — desetinná čárka, ne tečka. */
    private function cislo(float $hodnota): string
    {
        return str_replace('.', ',', (string) round($hodnota, 1));
    }

    private function popisGb(float $hodnota): string
    {
        return $this->cislo($hodnota).' GB';
    }

    private function kdy(\Illuminate\Support\Carbon $kdy): string
    {
        return match (true) {
            $kdy->isToday() => 'dnes '.$kdy->format('G:i'),
            $kdy->isYesterday() => 'včera '.$kdy->format('G:i'),
            default => $kdy->format('j. n. G:i'),
        };
    }
}
