<?php

namespace App\Services\Provoz;

use App\Models\CoupleState;
use App\Models\User;
use App\Support\Tabulky;
use Illuminate\Support\Facades\DB;

/**
 * Pauza dvojice („Pauza a plán") — 24 hodin, kdy aplikace mlčí.
 *
 * Obrazovka pauzu zapisuje do společného stavu (`pauseOn` a konec
 * `pauseUntil` v milisekundách). Dřív tvrdila, že „aplikace teď mlčí",
 * a upozornění přitom chodila dál — server o pauze nevěděl. Tady se na ni
 * ptá každé odeslání upozornění.
 */
class PauzaDvojice
{
    public static function bezi(User $clovek): bool
    {
        if (! Tabulky::je('couple_states') || ! Tabulky::je('gallery_space_user')) {
            return false;
        }

        $prostory = DB::table('gallery_space_user')->where('user_id', $clovek->id)->pluck('gallery_space_id');

        if ($prostory->isEmpty()) {
            return false;
        }

        return CoupleState::whereIn('couple_id', $prostory)->get(['data'])
            ->contains(fn (CoupleState $stav) => ! empty(($stav->data ?? [])['pauseOn'])
                && (int) (($stav->data ?? [])['pauseUntil'] ?? 0) > now()->getTimestampMs());
    }
}
