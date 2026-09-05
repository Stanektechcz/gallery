<?php

namespace App\Services\Provoz;

use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\SpaceContext;
use App\Jobs\SpustPlanovanouUlohu;

/**
 * Administrace, která přišla jako změna stavu.
 *
 * Prototyp administraci celou drží v komponentě: tlačítka volají `setState`
 * a ten stav se ukládá do `/api/state` jako všechno ostatní. Na `/api/admin`
 * nesáhne. Bez téhle třídy se proto po prvním kliknutí obrazovka a databáze
 * rozejdou — a to tiše: **stav tvrdil, že Makinka je host, databáze že správce.**
 * Obrazovka pak ukazuje něco, co nikde neplatí, a nikdo se to nedozví.
 *
 * Řeší se to dvěma pravidly:
 *
 * 1. Klíče `adm*` se **neukládají**. Jsou to pohled na serverová data, ne data
 *    sama; uložená kopie může jen zestárnout.
 * 2. Co klient poslal, se přečte jako **záměr** („tenhle účet má mít roli host")
 *    a provede se doopravdy. Odpověď pak nese skutečnost, klient si ji vezme
 *    a obrazovka se sama srovná.
 *
 * Co takhle nejde: **vytvoření klíče k API** (prototyp si tajemství vymýšlí na
 * klientovi, server ho nemá kam vrátit) a **přechod na placený tarif** (ten se
 * kupuje, nepřiděluje). Obojí zůstává na `/api/admin`.
 */
class AdminVeStavu
{
    /**
     * Klíče, které patří serveru.
     *
     * Klientova kopie se zahazuje a nahrazuje skutečností. Ve stavu tedy zůstávají,
     * ale píše je vždycky server — klient je jen čte. Bez toho by se obrazovka
     * neměla z čeho srovnat: odpověď na `PATCH` si klient jen odloží a překresluje
     * se až z `GET /api/state`.
     */
    public const SERVEROVE = ['admUsers', 'admKeys', 'admJobs', 'admLog', 'admRisk', 'admJobPause', 'admPlan'];

    public function __construct(
        private readonly AdministraceGalerie $administrace,
        private readonly PlanovaneUlohy $ulohy,
        private readonly AdministraceZasahy $zasahy,
    ) {}

    public function tykaSe(array $patch): bool
    {
        return array_intersect(self::SERVEROVE, array_keys($patch)) !== [];
    }

    /** Patch bez serverových klíčů — jen to, co se má opravdu uložit. */
    public function bezSpravy(array $patch): array
    {
        return array_diff_key($patch, array_flip(self::SERVEROVE));
    }

    /**
     * Provede, co klient zamýšlel, a vrátí skutečnost.
     *
     * Zásah, na který nemá kdo právo, se **mlčky neprovede**. Chybová hláška by
     * neměla kam jít — prototyp na ni není připravený — a vrácená skutečnost
     * obrazovku stejně srovná zpátky.
     *
     * @return array<string, mixed>
     */
    public function zpracuj(array $patch, GallerySpace $prostor, User $kdo): array
    {
        if (isset($patch['admUsers']) && is_array($patch['admUsers'])) {
            $this->ucty($patch['admUsers'], $prostor, $kdo);
        }

        if (isset($patch['admJobPause']) && is_array($patch['admJobPause'])) {
            $this->pauzy($patch['admJobPause'], $prostor, $kdo);
        }

        if (isset($patch['admRisk']) && is_array($patch['admRisk'])) {
            $this->rizika($patch['admRisk'], $prostor, $kdo);
        }

        if (isset($patch['admJobs']) && is_array($patch['admJobs'])) {
            $this->spusteni($patch['admJobs'], $prostor, $kdo);
        }

        if (isset($patch['admKeys']) && is_array($patch['admKeys'])) {
            $this->klice($patch['admKeys'], $prostor, $kdo);
        }

        if (isset($patch['admPlan'])) {
            $this->tarif((string) $patch['admPlan'], $prostor, $kdo);
        }

        return $this->skutecnost($prostor);
    }

    /**
     * Srovná uloženou kopii se skutečností.
     *
     * Volá se po zásahu přes `/api/admin`: bez toho by ve stavu zůstala kopie
     * z posledního kliknutí v obrazovce a otevřená aplikace by ukazovala starý
     * seznam, dokud by na něj někdo znovu neklikl.
     */
    public function uloz(GallerySpace $prostor): void
    {
        $stav = \App\Models\CoupleState::forCouple($prostor->id);
        $skutecnost = $this->skutecnost($prostor);

        /*
         * Zapisuje se jen skutečná změna.
         *
         * `applyPatch` zvedá `rev` a klient podle něj pozná konflikt. Zápis pro
         * nic by tedy znamenal, že otevřená aplikace při svém dalším uložení
         * dostane 409 a přijde o rozepsanou změnu — a to pokaždé, když si kdokoli
         * otevře administraci.
         */
        $ulozene = $stav->data ?? [];
        $zmeneno = false;

        foreach ($skutecnost as $klic => $hodnota) {
            if (($ulozene[$klic] ?? null) !== $hodnota) {
                $zmeneno = true;
                break;
            }
        }

        if ($zmeneno) {
            $stav->applyPatch($skutecnost);
        }
    }

    /** @return array<string, mixed> */
    public function skutecnost(GallerySpace $prostor): array
    {
        $prehled = $this->administrace->prehled($prostor->fresh());

        return [
            'admUsers' => $prehled['users'],
            'admKeys' => $prehled['keys'],
            'admJobs' => $prehled['jobs'],
            'admLog' => $prehled['log'],
            'admPlan' => $prehled['plan'],
            // Mapa „vyřešeno" ve tvaru, ve kterém ji prototyp čte.
            'admRisk' => collect($prehled['risks'])->mapWithKeys(
                fn (array $r) => [$r['id'] => (bool) ($r['hotovo'] ?? false)],
            )->all(),
            'admJobPause' => collect($prehled['jobs'])->mapWithKeys(
                fn (array $j) => [$j['id'] => $j['state'] === 'pozastavená'],
            )->all(),
        ];
    }

    // ——— jednotlivé záměry ———

    /** @param  list<array<string, mixed>>  $poslane */
    private function ucty(array $poslane, GallerySpace $prostor, User $kdo): void
    {
        // Účty smí měnit jen vlastník. Cizí zásah se neprovede a odpověď ho přebije.
        if ($kdo->id !== $prostor->owner_id) {
            return;
        }

        $dnesni = collect($this->administrace->ucty($prostor))->keyBy('id');

        foreach ($poslane as $radek) {
            if (! is_array($radek)) {
                continue;
            }

            $id = (string) ($radek['id'] ?? '');
            $stavajici = $dnesni->get($id);

            if ($stavajici === null) {
                $this->zasahy->pozvi($prostor, $kdo, (string) ($radek['mail'] ?? ''), (string) ($radek['role'] ?? 'host'));

                continue;
            }

            // Nová role „vlastník" znamená předání — jinou cestou vlastník nevzniká.
            if (($radek['role'] ?? null) === 'vlastník' && $stavajici['role'] !== 'vlastník') {
                $this->zasahy->predejVlastnictvi($prostor, $kdo, (int) $id);

                continue;
            }

            if (isset($radek['role']) && $radek['role'] !== $stavajici['role'] && $stavajici['role'] !== 'vlastník') {
                $this->zasahy->zmenRoli($prostor, $kdo, (int) $id, (string) $radek['role']);
            }

            if (isset($radek['state']) && $radek['state'] !== $stavajici['state']) {
                $chce = $radek['state'] !== 'bez přístupu';

                if ($chce !== ($stavajici['state'] !== 'bez přístupu')) {
                    $this->zasahy->nastavPristup($prostor, $kdo, (int) $id, $chce);
                }
            }
        }
    }

    /** @param  array<string, mixed>  $poslane */
    private function pauzy(array $poslane, GallerySpace $prostor, User $kdo): void
    {
        if (! $this->zasahy->jeSpravce($prostor, $kdo)) {
            return;
        }

        foreach ($poslane as $uloha => $stoji) {
            $uloha = (string) $uloha;

            if ($uloha === 'scheduler-heartbeat' || $this->ulohy->najdi($uloha) === null) {
                continue;
            }

            if ((bool) $stoji !== $this->ulohy->pozastavena($uloha)) {
                $stojiTed = $this->ulohy->prepni($uloha);

                AuditLog::record('admin.job.pause', null, ['popis' => $stojiTed
                    ? 'Úloha „'.$uloha.'" pozastavena'
                    : 'Úloha „'.$uloha.'" je zpět v plánu']);
            }
        }
    }

    /**
     * „Spustit teď".
     *
     * Prototyp úlohu jen přepne na `běží` a po vteřině na `hotovo` — nic se
     * nespustí. Přepnutí na `běží` je ale jednoznačný záměr, takže se z něj
     * pozná, co uživatel chtěl, a úloha se doopravdy zařadí.
     *
     * @param  list<array<string, mixed>>  $poslane
     */
    private function spusteni(array $poslane, GallerySpace $prostor, User $kdo): void
    {
        if (! $this->zasahy->jeSpravce($prostor, $kdo)) {
            return;
        }

        foreach ($poslane as $radek) {
            if (! is_array($radek) || ($radek['state'] ?? null) !== 'běží') {
                continue;
            }

            $uloha = (string) ($radek['id'] ?? '');

            if ($uloha === '' || $this->ulohy->najdi($uloha) === null) {
                continue;
            }

            SpustPlanovanouUlohu::dispatch($uloha);
            AuditLog::record('admin.job.run', null, ['popis' => 'Úloha „'.$uloha.'" spuštěna ručně']);
        }
    }

    /** @param  array<string, mixed>  $poslane */
    private function rizika(array $poslane, GallerySpace $prostor, User $kdo): void
    {
        if (! $this->zasahy->jeSpravce($prostor, $kdo)) {
            return;
        }

        foreach ($poslane as $riziko => $vyreseno) {
            if (! $vyreseno) {
                continue;
            }

            $popis = match ((string) $riziko) {
                'r1' => $this->spustKopii(),
                'r2' => $this->vysypKos($prostor),
                'r3' => 'Obnova ověřena — zkušební stažení proběhlo',
                default => null,
            };

            if ($popis !== null) {
                AuditLog::record('admin.risk', null, ['popis' => $popis]);
            }
        }
    }

    /** @param  list<array<string, mixed>>  $poslane */
    private function klice(array $poslane, GallerySpace $prostor, User $kdo): void
    {
        if (! $this->zasahy->jeSpravce($prostor, $kdo)) {
            return;
        }

        $dnesni = collect($this->administrace->klice($prostor))->keyBy('id');

        foreach ($poslane as $radek) {
            if (! is_array($radek)) {
                continue;
            }

            $stavajici = $dnesni->get((string) ($radek['id'] ?? ''));

            // Nový klíč tudy nevznikne: prototyp si tajemství vymýšlí na klientovi
            // a server by ho neměl kam vrátit. Zrušení naopak ano — na tom záleží.
            if ($stavajici === null || ($radek['state'] ?? null) !== 'zrušený' || $stavajici['state'] === 'zrušený') {
                continue;
            }

            $klic = PersonalAccessToken::find((int) $radek['id']);

            if ($klic !== null) {
                $klic->forceFill(['expires_at' => now()])->save();
                AuditLog::record('admin.key.revoke', null, [
                    'popis' => 'Klíč „'.$klic->name.'" zrušen — aplikace se odhlásí do minuty',
                ]);
            }
        }
    }

    private function tarif(string $novy, GallerySpace $prostor, User $kdo): void
    {
        // Placený tarif se kupuje, nepřiděluje — viz `AdminController::plan`.
        // Odpověď vrátí ten skutečný, takže se výběr v obrazovce sám vrátí zpátky.
        if ($kdo->id !== $prostor->owner_id || $novy === '') {
            return;
        }

        $this->zasahy->zmenTarifZdarma($prostor, $kdo, $novy);
    }

    private function spustKopii(): string
    {
        SpustPlanovanouUlohu::dispatch('mirror-backlog');

        return 'Druhá kopie spuštěna — originály se kopírují do cloudu';
    }

    private function vysypKos(GallerySpace $prostor): string
    {
        $pocet = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNotNull('trashed_at')
            ->update(['purge_after' => now()->subMinute()]);

        SpustPlanovanouUlohu::dispatch('trash-purge');

        return 'Koš vysypán — '.$pocet.' položek jde ke smazání';
    }
}
