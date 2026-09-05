<?php

namespace App\Services\Provoz;

use App\Models\AuditLog;
use App\Models\BillingPlan;
use App\Models\GallerySpace;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Services\Billing\EntitlementService;
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
 */
class AdministraceZasahy
{
    public function __construct(private readonly EntitlementService $tarify) {}

    public function jeVlastnik(GallerySpace $prostor, User $kdo): bool
    {
        return $kdo->id === $prostor->owner_id;
    }

    public function jeSpravce(GallerySpace $prostor, User $kdo): bool
    {
        if ($this->jeVlastnik($prostor, $kdo)) {
            return true;
        }

        $role = $prostor->members()->where('users.id', $kdo->id)->first()?->pivot->role;

        return in_array($role, ['owner', 'admin', 'editor'], true);
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
            $clen->tokens()->delete();
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

        if (! $this->jeVlastnik($prostor, $kdo)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ! $this->tarify->memberUsage($prostor)['can_add']) {
            return null;
        }

        $stavajici = User::where('email', $email)->first();

        if ($stavajici && $prostor->members()->where('users.id', $stavajici->id)->exists()) {
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

        if ($stavajici) {
            $pozvany->update(['invitation_token' => $token, 'invitation_accepted_at' => null]);
        }

        /*
         * Členství vzniká hned, ne až přijetím pozvánky.
         *
         * Dosavadní pozvánka zakládala účet, ale k prostoru ho nepřipojila — kdo
         * ji přijal, přihlásil se do aplikace bez jediné galerie.
         */
        $prostor->members()->syncWithoutDetaching([
            $pozvany->id => [
                'role' => AdministraceGalerie::ROLE_DOVNITR[$role] ?? 'viewer',
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

    private function clen(GallerySpace $prostor, int $id): ?User
    {
        return $prostor->members()->where('users.id', $id)->first();
    }

    /** Protokol je součást administrace — zásah bez záznamu se nepočítá. */
    private function zapis(string $akce, ?User $koho, string $popis): void
    {
        AuditLog::record($akce, $koho, ['popis' => $popis]);
    }
}
