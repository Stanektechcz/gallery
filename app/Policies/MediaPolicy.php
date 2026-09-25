<?php

namespace App\Policies;

use App\Models\MediaItem;
use App\Models\User;
use App\Services\Media\MazaniFotek;

class MediaPolicy
{
    public function __construct(private readonly MazaniFotek $mazani) {}

    public function view(User $user, MediaItem $media): bool
    {
        return $user->gallerySpaces()
            ->where('gallery_spaces.id', $media->gallery_space_id)
            ->exists();
    }

    public function update(User $user, MediaItem $media): bool
    {
        if ($user->read_only_mode) {
            return false;
        }

        return $this->view($user, $media);
    }

    /**
     * Mazat (a vracet z koše) smí jen dvojice prostoru.
     *
     * Dřív stačilo `users.role = owner` — ten ale má každý zaregistrovaný
     * účet, takže host cizí galerie mazal její fotky. `can_delete` v členství
     * už oprávnění není: o mazání rozhoduje dohoda dvojice (`MazaniFotek`).
     * Tohle pravidlo říká jen „kdo smí vůbec sáhnout", ne jestli fotka jde
     * rovnou do koše, nebo čeká na souhlas.
     */
    public function delete(User $user, MediaItem $media): bool
    {
        $prostor = $media->gallerySpace;

        return $prostor !== null && $this->mazani->jeClenDvojice($prostor, $user);
    }

    public function restore(User $user, MediaItem $media): bool
    {
        return $this->delete($user, $media);
    }
}
