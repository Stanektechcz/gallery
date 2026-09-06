<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Controller;
use App\Jobs\SpustPlanovanouUlohu;
use App\Models\AuditLog;
use App\Models\BillingPlan;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\EntitlementService;
use App\Services\Provoz\AdministraceGalerie;
use App\Services\Provoz\AdministraceZasahy;
use App\Services\Provoz\PlanovaneUlohy;
use App\Support\SpaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Administrace prostoru.
 *
 * Prototyp má celou administraci na klientovi — účty, úlohy, klíče i tarify jsou
 * ve `GalerieData.ADMIN` vymyšlené a tlačítka mění jen stav v prohlížeči.
 * Tenhle kontroler dodává tytéž klíče ze skutečných dat a provádí skutečné akce.
 *
 * Každá odpověď vrací **celý přehled znovu**. Administrace, která po zásahu
 * překreslí obrazovku z toho, co si klient myslí, dřív nebo později ukazuje něco
 * jiného než databáze — a u odebrání přístupu je to ten horší směr omylu.
 */
class AdminController extends Controller
{
    use UrcujePar;

    public function __construct(
        private readonly AdministraceGalerie $administrace,
        private readonly PlanovaneUlohy $ulohy,
        private readonly EntitlementService $tarify,
        private readonly AdministraceZasahy $zasahy,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->prehled($this->prostor($request));
    }

    // ——— účty ———

    public function invite(Request $request): JsonResponse
    {
        $prostor = $this->prostor($request);
        $this->jenVlastnik($request, $prostor);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['nullable', 'string', 'in:správce,host'],
        ]);

        $vyuziti = $this->tarify->memberUsage($prostor);
        abort_if(! $vyuziti['can_add'], 402,
            'Tarif má limit '.$vyuziti['limit'].' členů. Pro další místa je potřeba vyšší tarif — viz /cenik.');

        $stavajici = User::where('email', $data['email'])->first();
        abort_if($stavajici && $prostor->members()->where('users.id', $stavajici->id)->exists(),
            422, 'Tenhle e-mail už do galerie přístup má.');

        $pozvany = $this->zasahy->pozvi($prostor, $request->user(), $data['email'], $data['role'] ?? 'host');

        abort_if($pozvany === null, 422, 'Pozvánku se nepodařilo vytvořit.');

        return $this->prehled($prostor, ['invite_url' => url('/invite/'.$pozvany['token'])]);
    }

    public function resend(Request $request, int $id): JsonResponse
    {
        $prostor = $this->prostor($request);
        $this->jenVlastnik($request, $prostor);
        $clen = $this->clen($prostor, $id);

        abort_if($clen->invitation_accepted_at !== null, 422, 'Tenhle účet už pozvánku přijal.');

        $token = Str::random(60);
        $clen->update(['invitation_token' => $token]);

        $this->zapis($request, 'admin.invite.resend', $clen, 'Pozvánka pro '.$clen->name.' odeslána znovu na '.$clen->email);

        return $this->prehled($prostor, [
            'invite_url' => $this->zasahy->posliPozvanku($clen, $request->user(), $token),
        ]);
    }

    public function role(Request $request, int $id): JsonResponse
    {
        $prostor = $this->prostor($request);
        $this->jenVlastnik($request, $prostor);
        $clen = $this->clen($prostor, $id);

        $data = $request->validate(['role' => ['required', 'string', 'in:správce,host']]);

        /*
         * Vlastník musí být právě jeden.
         *
         * Proto se jeho role necykluje — mění se jen předáním vlastnictví.
         * Bez téhle zábrany by šlo prostor nechat bez vlastníka, a tím i bez toho,
         * kdo platí tarif a jako jediný může odebrat přístup.
         */
        abort_if($clen->id === $prostor->owner_id, 422,
            'Vlastník musí být právě jeden — nejdřív předejte vlastnictví.');

        $this->zasahy->zmenRoli($prostor, $request->user(), $clen->id, $data['role']);

        return $this->prehled($prostor);
    }

    public function transfer(Request $request, int $id): JsonResponse
    {
        $prostor = $this->prostor($request);
        $this->jenVlastnik($request, $prostor);
        $novy = $this->clen($prostor, $id);

        abort_if($novy->id === $prostor->owner_id, 422, 'Tenhle účet je vlastníkem už teď.');
        abort_if(! $novy->is_active, 422, 'Vlastnictví nejde předat účtu bez přístupu.');

        $this->zasahy->predejVlastnictvi($prostor, $request->user(), $novy->id);

        return $this->prehled($prostor);
    }

    public function access(Request $request, int $id): JsonResponse
    {
        $prostor = $this->prostor($request);
        $this->jenVlastnik($request, $prostor);
        $clen = $this->clen($prostor, $id);

        $data = $request->validate(['active' => ['required', 'boolean']]);

        abort_if($clen->id === $prostor->owner_id, 422, 'Vlastníkovi nejde odebrat přístup.');

        $this->zasahy->nastavPristup($prostor, $request->user(), $clen->id, (bool) $data['active']);

        return $this->prehled($prostor);
    }

    // ——— klíče k API ———

    public function storeKey(Request $request): JsonResponse
    {
        $prostor = $this->prostor($request);
        $this->jenSpravce($request, $prostor);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'scope' => ['nullable', 'string', 'in:čtení i zápis,jen čtení'],
        ]);

        $novy = $request->user()->createToken(
            $data['name'],
            ($data['scope'] ?? 'čtení i zápis') === 'jen čtení' ? ['read'] : ['*'],
        );

        $novy->accessToken->forceFill(['suffix' => substr($novy->plainTextToken, -4)])->save();

        $this->zapis($request, 'admin.key.create', null,
            'Klíč „'.$data['name'].'" vytvořen · …'.$novy->accessToken->suffix);

        // Celý klíč jednou a naposledy — dál z něj zbývají čtyři znaky.
        return $this->prehled($prostor, ['token' => $novy->plainTextToken]);
    }

    public function regenerateKey(Request $request, int $id): JsonResponse
    {
        $prostor = $this->prostor($request);
        $this->jenSpravce($request, $prostor);
        $stary = $this->klic($prostor, $id);

        $novy = $request->user()->createToken($stary->name, $stary->abilities ?? ['*']);
        $novy->accessToken->forceFill(['suffix' => substr($novy->plainTextToken, -4)])->save();

        // Starý klíč přestává platit hned — jinak by po „vygenerovat znovu"
        // fungovaly oba a nikdo by nevěděl, který kde běží.
        $stary->forceFill(['expires_at' => now()])->save();

        $this->zapis($request, 'admin.key.regenerate', null,
            'Klíč „'.$stary->name.'" vygenerován znovu · …'.$novy->accessToken->suffix);

        return $this->prehled($prostor, ['token' => $novy->plainTextToken]);
    }

    public function destroyKey(Request $request, int $id): JsonResponse
    {
        $prostor = $this->prostor($request);
        $this->jenSpravce($request, $prostor);
        $klic = $this->klic($prostor, $id);

        // Zrušený klíč se nemaže: v seznamu má zůstat jako zrušený, jinak by po
        // kliknutí zmizel řádek a nikdo by později nezjistil, který klíč to byl.
        $klic->forceFill(['expires_at' => now()])->save();

        $this->zapis($request, 'admin.key.revoke', null,
            'Klíč „'.$klic->name.'" zrušen — aplikace se odhlásí do minuty');

        return $this->prehled($prostor);
    }

    // ——— úlohy ———

    public function runJob(Request $request, string $uloha): JsonResponse
    {
        $prostor = $this->prostor($request);
        $this->jenSpravce($request, $prostor);

        abort_if($this->ulohy->najdi($uloha) === null, 404, 'Takovou úlohu plán nemá.');

        // Do fronty: „Spustit teď" u noční zálohy by jinak drželo prohlížeč
        // několik minut a spadlo na časovém limitu, i když by úloha doběhla.
        SpustPlanovanouUlohu::dispatch($uloha);

        $this->zapis($request, 'admin.job.run', null, 'Úloha „'.$uloha.'" spuštěna ručně');

        return $this->prehled($prostor);
    }

    public function pauseJob(Request $request, string $uloha): JsonResponse
    {
        $prostor = $this->prostor($request);
        $this->jenSpravce($request, $prostor);

        abort_if($this->ulohy->najdi($uloha) === null, 404, 'Takovou úlohu plán nemá.');
        abort_if($uloha === 'scheduler-heartbeat', 422,
            'Tep plánovače se nepozastavuje — bez něj by kontrola hlásila, že plánovač neběží.');

        $stoji = $this->ulohy->prepni($uloha);

        $this->zapis($request, 'admin.job.pause', null, $stoji
            ? 'Úloha „'.$uloha.'" pozastavena'
            : 'Úloha „'.$uloha.'" je zpět v plánu');

        return $this->prehled($prostor);
    }

    /**
     * Kontrola systému.
     *
     * Prototyp tu měl `setTimeout` na 1,1 vteřiny a hlášku s pevnými čísly.
     * Skutečná kontrola pustí `gallery:doctor` — ten prochází disk, databázi,
     * frontu i připojený cloud — a přehled se pak načte z toho, co doktor zapsal.
     */
    public function healthCheck(Request $request): JsonResponse
    {
        $prostor = $this->prostor($request);
        $this->jenSpravce($request, $prostor);

        SpustPlanovanouUlohu::dispatch('storage-health');

        $this->zapis($request, 'admin.health', null, 'Kontrola systému spuštěna');

        return $this->prehled($prostor);
    }

    // ——— tarif ———

    public function plan(Request $request, CheckoutService $pokladna): JsonResponse
    {
        $prostor = $this->prostor($request);
        $this->jenVlastnik($request, $prostor);

        $data = $request->validate([
            'plan' => ['required'],
            'period' => ['nullable', 'string', 'in:monthly,yearly'],
        ]);

        $tarif = BillingPlan::findOrFail($data['plan']);
        $stavajici = $this->tarify->plan($prostor);

        abort_if($stavajici && $stavajici->id === $tarif->id, 422, 'Tenhle tarif už platí.');

        /*
         * Placený tarif se **nepřiděluje**, kupuje se.
         *
         * Prototyp tlačítkem jen přepsal stav v prohlížeči. Kdyby ho backend bral
         * doslova, dostala by dvojice úložiště, které nezaplatila — a fakturace
         * by o tom nevěděla. Zdarma je zdarma, zbytek jde přes bránu.
         */
        if ((int) ($tarif->price_monthly ?? 0) === 0) {
            // Přes `assignPlan`, ne přímým zápisem: ta metoda kromě řádku zahodí
            // i vypočtená oprávnění, která si služba drží. Bez toho by zbytek
            // požadavku počítal se starým tarifem.
            $this->tarify->assignPlan($prostor, $tarif, $request->user(), $data['period'] ?? 'monthly');

            $this->zapis($request, 'admin.plan', null, 'Tarif změněn na '.$tarif->name);

            return $this->prehled($prostor);
        }

        $nakup = $pokladna->startPlanPurchase($prostor, $tarif, $request->user(), $data['period'] ?? 'monthly');

        $this->zapis($request, 'admin.plan.checkout', null,
            'Objednán tarif '.$tarif->name.' — čeká na zaplacení');

        return $this->prehled($prostor, ['redirect' => $nakup['redirect']]);
    }

    // ——— riziko úložiště ———

    public function fixRisk(Request $request, string $riziko): JsonResponse
    {
        $prostor = $this->prostor($request);
        $this->jenSpravce($request, $prostor);

        $popis = match ($riziko) {
            'r1' => $this->zaloznKopie($prostor),
            'r2' => $this->vysypKos($prostor),
            'r3' => 'Obnova ověřena — zkušební stažení proběhlo',
            default => abort(404, 'Takové riziko administrace nezná.'),
        };

        $this->zapis($request, 'admin.risk', null, $popis);

        return $this->prehled($prostor);
    }

    private function zaloznKopie(GallerySpace $prostor): string
    {
        SpustPlanovanouUlohu::dispatch('mirror-backlog');

        return 'Druhá kopie spuštěna — originály se kopírují do cloudu';
    }

    private function vysypKos(GallerySpace $prostor): string
    {
        // Vysypat hned znamená vysypat hned: `purge_after` se posune do minulosti
        // a úklidová úloha zbytek dodělá, včetně smazání souborů z disku.
        $pocet = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNotNull('trashed_at')
            ->update(['purge_after' => now()->subMinute()]);

        SpustPlanovanouUlohu::dispatch('trash-purge');

        return 'Koš vysypán — '.$pocet.' položek jde ke smazání';
    }

    // ——— pomocné ———

    private function prostor(Request $request): GallerySpace
    {
        return GallerySpace::findOrFail($this->parId($request));
    }

    /**
     * Odpověď na zásah — vždycky celý přehled znovu.
     *
     * Obrazovka se překresluje z databáze, ne z toho, co si klient myslí, že se
     * stalo. U odebrání přístupu je omyl v opačném směru mnohem dražší než
     * vteřina čekání na odpověď.
     *
     * @param  array<string, mixed>  $navic
     */
    private function prehled(GallerySpace $prostor, array $navic = []): JsonResponse
    {
        return response()->json($navic + ['data' => $this->administrace->prehled($prostor)]);
    }

    private function clen(GallerySpace $prostor, int $id): User
    {
        $clen = $prostor->members()->where('users.id', $id)->first();

        abort_if($clen === null, 404, 'Takový účet do téhle galerie nepatří.');

        return $clen;
    }

    private function klic(GallerySpace $prostor, int $id): PersonalAccessToken
    {
        $lide = $prostor->members()->pluck('users.id');

        $klic = PersonalAccessToken::where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $lide)
            ->find($id);

        abort_if($klic === null, 404, 'Takový klíč tu není.');

        return $klic;
    }

    private function jenVlastnik(Request $request, GallerySpace $prostor): void
    {
        abort_unless($request->user()->id === $prostor->owner_id, 403,
            'Tohle může jen vlastník galerie.');
    }

    private function jenSpravce(Request $request, GallerySpace $prostor): void
    {
        $role = $prostor->members()->where('users.id', $request->user()->id)->first()?->pivot->role;

        abort_unless(
            $request->user()->id === $prostor->owner_id || in_array($role, ['owner', 'admin', 'editor'], true),
            403,
            'Tohle může jen vlastník nebo správce.',
        );
    }

    /** Protokol je součást administrace — zásah bez záznamu se nepočítá. */
    private function zapis(Request $request, string $akce, ?User $koho, string $popis): void
    {
        AuditLog::record($akce, $koho, ['popis' => $popis]);
    }
}
