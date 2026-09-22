<?php

use App\Models\CoupleState;
use App\Models\GallerySpace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Komentáře u fotek ze sdíleného stavu do `media_comments`.
 *
 * Počítač držel vlákno komentářů ve stavu dvojice (`lbCom`); od téhle verze
 * čte a píše komentáře přes `/api/v1/media/{uuid}/comments`. Co už napsané
 * je, se přenese, aby po nasazení nezmizelo: autor se pozná podle křestního
 * jména (jinak vlastník prostoru), čas zápisu stav neznal, takže se použije
 * okamžik převodu. Pak se `lbCom` ze stavu odebere, ať se nepřenese dvakrát.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('couple_states') || ! Schema::hasTable('media_comments')) {
            return;
        }

        CoupleState::query()->each(function (CoupleState $stav) {
            $vlakna = ($stav->data ?? [])['lbCom'] ?? null;

            if (! is_array($vlakna) || $vlakna === []) {
                return;
            }

            $prostor = GallerySpace::find($stav->couple_id);

            if ($prostor !== null) {
                $clenove = $prostor->members()->get(['users.id', 'users.name']);
                $autor = function (string $kdo) use ($clenove, $prostor): int {
                    $krestni = Str::lower(Str::before(trim($kdo), ' '));
                    $clen = $clenove->first(fn ($u) => Str::lower(Str::before(trim((string) $u->name), ' ')) === $krestni);

                    return (int) ($clen->id ?? $prostor->owner_id);
                };

                foreach ($vlakna as $uuid => $radky) {
                    $foto = DB::table('media_items')
                        ->where('gallery_space_id', $prostor->id)
                        ->where('uuid', (string) $uuid)
                        ->value('id');

                    if ($foto === null || ! is_array($radky)) {
                        continue;
                    }

                    foreach ($radky as $r) {
                        $text = trim((string) (((array) $r)['text'] ?? ''));

                        if ($text === '') {
                            continue;
                        }

                        DB::table('media_comments')->insert([
                            'media_item_id' => $foto,
                            'user_id' => $autor((string) (((array) $r)['who'] ?? '')),
                            'body' => mb_substr($text, 0, 2000),
                            'is_private' => false,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            $stav->zapomen(['lbCom']);
        });
    }

    public function down(): void
    {
        // Komentáře zůstávají v tabulce, odkud je čte počítač i staré rozhraní.
    }
};
