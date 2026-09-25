<?php

namespace App\Services\Auth;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Smí tenhle účet do aplikace dvojice?
 *
 * Dvě díry, které měly společnou příčinu — o vstupu rozhodovalo jen heslo:
 *
 *  - **Odebraný přístup** (`is_active = false`) zrušil tokeny, ale nové
 *    přihlášení heslem nebo otiskem vydalo token znovu.
 *  - **Host** (členství `viewer`/`contributor`) má podle administrace vidět
 *    „jen sdílené odkazy". API galerie ho přitom pouštělo ke všemu — deníku,
 *    financím, trezoru i ke smazání společného stavu.
 *
 * Prostor se určuje stejně jako v `UrcujePar` (první prostor účtu), aby role
 * i data vždy patřily témuž páru.
 */
class PristupDoGalerie
{
    /** Role, které aplikaci dvojice používají celou. */
    public const ROLE_DVOJICE = ['owner', 'admin', 'editor'];

    /** Důvod odmítnutí pro člověka, nebo `null`, když přístup má. */
    public function proc(User $user): ?string
    {
        // `null` je čerstvě založený účet, u kterého výchozí hodnotu doplnila databáze.
        if ($user->is_active === false) {
            return 'Tenhle účet do galerie přístup nemá. Obnovit ho může vlastník galerie.';
        }

        // Týž prostor, jaký použije `UrcujePar::parId` — relace má pevné pořadí.
        // Dřív tu byl nesetříděný `first()`: role se posuzovala v jedné galerii
        // a požadavek pak běžel v druhé.
        $prostor = $user->gallerySpaces()->first();

        // Účet bez prostoru se přihlásit smí — založí si ho, nebo přijme pozvánku.
        if ($prostor === null) {
            return null;
        }

        // Vlastník prostoru je vlastník, i kdyby mu v členství zůstala výchozí role.
        if ((int) $prostor->owner_id !== (int) $user->id
            && ! in_array((string) $prostor->pivot->role, self::ROLE_DVOJICE, true)) {
            return 'Tenhle účet je host galerie — vidí jen odkazy, které mu někdo pošle.';
        }

        return null;
    }

    /**
     * Členové prostoru, kteří tvoří samotnou dvojici — bez hostů a bez účtů,
     * kterým byl přístup odebraný.
     *
     * Sdílené na jednom místě, protože upozornění dvojice (výzva Zároveň,
     * vzpomínky, výročí) tuhle podmínku potřebují každé zvlášť a lišila se
     * příkaz od příkazu — host nebo odebraný účet tak dostával upozornění na
     * obsah, ke kterému nesmí.
     *
     * @return Collection<int, User>
     */
    public function dvojice(GallerySpace $prostor): Collection
    {
        return $prostor->members->filter(function (User $clen) use ($prostor) {
            $dvojice = (int) $prostor->owner_id === (int) $clen->id
                || in_array((string) $clen->pivot->role, self::ROLE_DVOJICE, true);

            return $dvojice && $this->proc($clen) === null;
        })->values();
    }
}
