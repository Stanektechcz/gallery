<?php

namespace App\Services\Provoz;

use App\Models\AuditLog;
use App\Models\BillingPlan;
use App\Models\GallerySpace;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Notifications\InvitationNotification;
use App\Services\Auth\PristupDoGalerie;
use App\Services\Billing\EntitlementService;
use App\Services\Media\MazaniFotek;
use App\Services\Notifications\OdberyPush;
use App\Support\Provozovatel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Zásahy do administrace na jednom místě.
 *
 * Volají je dvě cesty: `AdminController` (endpointy `/api/admin/*`) a
 * [AdminVeStavu] (záměr, který přišel jako změna stavu z prototypu). Kdyby si
 * pravidla — vlastník je právě jeden, odebraný přístup ruší tokeny — držela každá
 * cesta sama, dřív nebo později by platila jen v jedné z nich.
 *
 * Metody **nevyhazují výjimky na porušení práv**; vrací `false`. Volání ze stavu
 * nemá kam chybu zobrazit a odpověď se skutečností obrazovku stejně srovná;
 * kontroler si nad tím dělá vlastní kontroly, aby uměl odpovědět 403.
 *
 * **Dvojice se administrací nemění.** Mazání fotek čeká na souhlas druhého
 * z dvojice (`MazaniFotek`) a dvojici tvoří role v prostoru
 * (`PristupDoGalerie::ROLE_DVOJICE`). Kdyby šlo partnera přeřadit na hosta,
 * zůstal by vlastník v dvojici sám a mazal by bez souhlasu. Do úplné dvojice
 * zase nesmí přibýt nikdo další — změnou role, pozvánkou ani předáním
 * vlastnictví hostovi —, protože by schvaloval mazání místo partnera. Obojí
 * hlídají `opoustiDvojici` a `pridavaDoUplneDvojice`; kontroler se ptá týchž
 * metod, aby uměl říct proč.
 */
class AdministraceZasahy
{
    public function __construct(
        private readonly EntitlementService $tarify,
        private readonly MazaniFotek $mazani,
    ) {}

    public function jeVlastnik(GallerySpace $prostor, User $kdo): bool
    {
        return $kdo->id === $prostor->owner_id;
    }

    public function jeSpravce(GallerySpace $prostor, User $kdo): bool
    {
        if ($this->jeVlastnik($prostor, $kdo)) {
            return true;
        }

        return in_array($this->rolePivotu($prostor, $kdo), PristupDoGalerie::ROLE_DVOJICE, true);
    }

    /** Patří účet k dvojici — vlastník nebo role dvojice v tomhle prostoru? */
    public function jeVeDvojici(GallerySpace $prostor, User $clen): bool
    {
        return (int) $clen->id === (int) $prostor->owner_id
            || in_array($this->rolePivotu($prostor, $clen), PristupDoGalerie::ROLE_DVOJICE, true);
    }

    /**
     * Vyřadila by nová role člena z dvojice?
     *
     * Pak by mazání fotek přestalo čekat na jeho souhlas — `MazaniFotek` počítá
     * dvojici podle rolí a vlastník, který v ní zůstane sám, maže rovnou.
     *
     * @param  string  $role  role z obrazovky („správce", „host") nebo z členství
     */
    public function opoustiDvojici(GallerySpace $prostor, User $clen, string $role): bool
    {
        return $this->jeVeDvojici($prostor, $clen)
            && ! in_array($this->roleDovnitr($role), PristupDoGalerie::ROLE_DVOJICE, true);
    }

    /**
     * Přibyl by s touhle rolí do už úplné dvojice někdo další?
     *
     * Souhlas s mazáním dává kdokoli z dvojice kromě navrhovatele. Třetí člen,
     * kterého si vlastník vybere, by tak schvaloval místo partnera. Dokud je
     * vlastník v prostoru sám, partner teprve přibývá a to je v pořádku.
     *
     * @param  User|null  $clen  `null` u pozvánky, účet v prostoru ještě není
     * @param  string  $role  role z obrazovky nebo z členství (`owner` u předání)
     */
    public function pridavaDoUplneDvojice(GallerySpace $prostor, ?User $clen, string $role): bool
    {
        return in_array($this->roleDovnitr($role), PristupDoGalerie::ROLE_DVOJICE, true)
            && ($clen === null || ! $this->jeVeDvojici($prostor, $clen))
            && $this->mazani->pocetDvojice($prostor) >= 2;
    }

    public function zmenRoli(GallerySpace $prostor, User $kdo, int $komu, string $role): bool
    {
        $clen = $this->clen($prostor, $komu);

        // Vlastník musí být právě jeden — jeho role se nemění, jen předává.
        if (! $this->jeVlastnik($prostor, $kdo) || $clen === null
            || $clen->id === $prostor->owner_id
            || ! isset(AdministraceGalerie::ROLE_DOVNITR[$role])) {
            return false;
        }

        if ($this->opoustiDvojici($prostor, $clen, $role)
            || $this->pridavaDoUplneDvojice($prostor, $clen, $role)) {
            return false;
        }

        // „Správce" u člena dvojice nic nemění. Zápis by přepsal `admin` na
        // `editor` a správce by potichu přišel o trvalé mazání z koše.
        if ($this->jeVeDvojici($prostor, $clen)) {
            return false;
        }

        $prostor->members()->updateExistingPivot($clen->id, [
            'role' => AdministraceGalerie::ROLE_DOVNITR[$role],
        ]);

        $this->zapis('admin.role', $clen, $clen->name.' má nyní roli '.$role);

        return true;
    }

    public function predejVlastnictvi(GallerySpace $prostor, User $kdo, int $komu): bool
    {
        $novy = $this->clen($prostor, $komu);

        if (! $this->jeVlastnik($prostor, $kdo) || $novy === null
            || $novy->id === $prostor->owner_id || ! $novy->is_active) {
            return false;
        }

        // Předchozí vlastník zůstává ve dvojici jako správce — host, který by
        // vlastnictví převzal, by v úplné dvojici byl třetí, kdo schvaluje mazání.
        if ($this->pridavaDoUplneDvojice($prostor, $novy, 'owner')) {
            return false;
        }

        $puvodni = User::find($prostor->owner_id);

        $prostor->update(['owner_id' => $novy->id]);
        $prostor->members()->updateExistingPivot($novy->id, ['role' => 'owner']);

        // Z předchozího vlastníka se stane správce — přijít o vlastní galerii
        // předáním tarifu není to, co kdokoli tím tlačítkem myslí.
        if ($puvodni) {
            $prostor->members()->updateExistingPivot($puvodni->id, ['role' => 'admin']);
        }

        $this->zapis('admin.transfer', $novy, 'Vlastnictví předáno · '.$novy->name.' platí tarif'
            .($puvodni ? ', '.$puvodni->name.' je správce' : ''));

        return true;
    }

    public function nastavPristup(GallerySpace $prostor, User $kdo, int $komu, bool $aktivni): bool
    {
        $clen = $this->clen($prostor, $komu);

        if (! $this->jeVlastnik($prostor, $kdo) || $clen === null || $clen->id === $prostor->owner_id) {
            return false;
        }

        $clen->update(['is_active' => $aktivni]);

        if (! $aktivni) {
            // Odebraný přístup musí platit hned. Bez zrušení tokenů by se telefon
            // s uloženým přihlášením dostal dovnitř dál — a to je smysl akce.
            // Stejně tak upozornění: jejich text je vidět i na zamčeném telefonu.
            $clen->tokens()->delete();
            OdberyPush::zrusVse($clen);
            // Otisk by vydal nový token hned po obnovení přístupu, a cookie
            // „zapamatovat si mě" by otevřela staré rozhraní — ruší se obojí.
            WebauthnCredential::zrusKromeTokenu($clen);
            $clen->forceFill(['remember_token' => Str::random(60)])->save();
        }

        $this->zapis('admin.access', $clen, $aktivni
            ? 'Přístup pro '.$clen->name.' obnoven'
            : $clen->name.' už do galerie nemá přístup');

        return true;
    }

    /** @return array{user: User, token: string}|null */
    public function pozvi(GallerySpace $prostor, User $kdo, string $email, string $role = 'host'): ?array
    {
        $email = trim($email);
        $roleDovnitr = AdministraceGalerie::ROLE_DOVNITR[$role] ?? 'viewer';

        if (! $this->jeVlastnik($prostor, $kdo)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ! $this->tarify->memberUsage($prostor)['can_add']
            // Pozvaný správce by v úplné dvojici schvaloval mazání místo partnera.
            || $this->pridavaDoUplneDvojice($prostor, null, $roleDovnitr)) {
            return null;
        }

        /*
         * Adresa provozovatele se pozvánkou nezakládá.
         *
         * Pozvánka vrací odkaz tomu, kdo zve — účet na adresu provozovatele by
         * si tak vlastník kterékoli galerie otevřel sám a byl by provozovatelem
         * celé instalace. Viz `Provozovatel`.
         */
        if (! Provozovatel::smiNastavit($email, $kdo)) {
            return null;
        }

        $stavajici = User::where('email', $email)->first();

        if ($stavajici && $prostor->members()->where('users.id', $stavajici->id)->exists()) {
            return null;
        }

        /*
         * Existující účet pozvánka neotevře.
         *
         * Bez téhle podmínky stačilo znát cizí e-mail: zápis níž přepsal tomu
         * účtu `invitation_token` a odkaz se vrátil volajícímu. Kdo ho otevřel,
         * nastavil si na cizí účet nové heslo a přihlásil se do cizího deníku,
         * trezoru a financí — původní majitel se naopak nepřihlásil už nikdy.
         *
         * Dřív to hlídalo jen `invitation_accepted_at`, jenže to nemají ani účty
         * ze seedu, ani čekající pozvaní jiných galerií. Stojí to tady, a ne jen
         * v kontroleru, protože zvát umí i stavová cesta (`AdminVeStavu`).
         */
        if ($stavajici && $this->ucetUzPatriJinam($stavajici, $kdo)) {
            return null;
        }

        $token = Str::random(60);

        $pozvany = $stavajici ?? User::create([
            'uuid' => (string) Str::uuid(),
            'name' => Str::of(Str::before($email, '@'))->replaceMatches('/[._-]+/', ' ')->title()->toString(),
            'email' => $email,
            'role' => 'partner',
            // Náhodné heslo, které nikdo nezná: účet se otevírá odkazem z pozvánky.
            'password' => Hash::make(Str::random(32)),
            'invitation_token' => $token,
            'invited_by' => true,
            'invited_by_user_id' => $kdo->id,
            'is_active' => true,
        ]);

        // Sem se dostane jen účet, který tentýž vlastník založil a nikdo ho
        // nepřevzal (viz `ucetUzPatriJinam`) — nový token staré odkazy zneplatní.
        $pozvany->forceFill([
            'invitation_token' => $token,
            'invitation_accepted_at' => null,
            'invitation_sent_at' => now(),
        ])->save();

        /*
         * Členství vzniká hned, ne až přijetím pozvánky.
         *
         * Dosavadní pozvánka zakládala účet, ale k prostoru ho nepřipojila — kdo
         * ji přijal, přihlásil se do aplikace bez jediné galerie.
         */
        $prostor->members()->syncWithoutDetaching([
            $pozvany->id => [
                'role' => $roleDovnitr,
                'joined_at' => now(),
            ],
        ]);

        $this->zapis('admin.invite', $pozvany, 'Pozvánka odeslána na '.$pozvany->email);
        $this->posliPozvanku($pozvany, $kdo, $token);

        return ['user' => $pozvany, 'token' => $token];
    }

    /** Jen tarif zdarma. Placený se kupuje — viz `AdminController::plan`. */
    public function zmenTarifZdarma(GallerySpace $prostor, User $kdo, string $tarifId): bool
    {
        $tarif = BillingPlan::find($tarifId);

        if (! $this->jeVlastnik($prostor, $kdo) || $tarif === null
            || (int) ($tarif->price_monthly ?? 0) !== 0
            || $this->tarify->plan($prostor)?->id === $tarif->id) {
            return false;
        }

        $this->tarify->assignPlan($prostor, $tarif, $kdo);
        $this->zapis('admin.plan', null, 'Tarif změněn na '.$tarif->name);

        return true;
    }

    /**
     * Pošle pozvánku a vrátí odkaz.
     *
     * Odkaz se vrací i při úspěchu schválně: když e-mail nedorazí (a u pozvánek
     * to bývá spam složka), je jediná cesta dovnitř to, že ho vlastník předá sám.
     */
    public function posliPozvanku(User $komu, User $odKoho, string $token): string
    {
        $odkaz = url('/invite/'.$token);

        try {
            $komu->notify(new InvitationNotification($odkaz, $odKoho->name));
        } catch (\Throwable $e) {
            report($e);
        }

        return $odkaz;
    }

    /**
     * Patří existující účet s tímhle e-mailem někomu, koho pozvánka nesmí otevřít?
     *
     * Pozvánka nasazuje účtu nový token a jeho přijetí nové heslo. Samotné
     * „pozvánku ještě nepřijal" (`invitation_accepted_at === null`) nestačí:
     * tak vypadají účty ze seedu i čekající pozvaní **jiných** galerií, a vlastník
     * cizí galerie si tak otevřel účet, který mu nepatří.
     *
     * Znovu pozvat jde jen účet, který tentýž vlastník sám založil, nikdo ho
     * nepřevzal a nepatří do žádné galerie. Každý jiný existující účet je obsazený
     * — připojení existujícího účtu do další galerie pozvánkou neumíme.
     */
    public function ucetUzPatriJinam(User $stavajici, User $kdo): bool
    {
        return $stavajici->invitation_accepted_at !== null
            || $stavajici->invitation_token === null
            // Bez přetypování by MySQL s id jako řetězcem zablokoval i oprávněné znovupozvání.
            || (int) $stavajici->invited_by_user_id !== (int) $kdo->id
            || $stavajici->gallerySpaces()->exists();
    }

    private function clen(GallerySpace $prostor, int $id): ?User
    {
        return $prostor->members()->where('users.id', $id)->first();
    }

    /** Z databáze, ne z načteného pivotu — ten může být starší než předání vlastnictví. */
    private function rolePivotu(GallerySpace $prostor, User $kdo): ?string
    {
        $role = $prostor->members()->where('users.id', $kdo->id)->first()?->pivot->role;

        return $role === null ? null : (string) $role;
    }

    /** Role z obrazovky na roli členství; role členství projde beze změny. */
    private function roleDovnitr(string $role): string
    {
        return AdministraceGalerie::ROLE_DOVNITR[$role] ?? $role;
    }

    /** Protokol je součást administrace — zásah bez záznamu se nepočítá. */
    private function zapis(string $akce, ?User $koho, string $popis): void
    {
        AuditLog::record($akce, $koho, ['popis' => $popis]);
    }
}
