<?php

namespace Tests\Feature\Notifications;

use App\Models\GallerySpace;
use App\Models\User;
use App\Notifications\GalleryNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Upozornění „pro prostor" patří dvojici, ne hostům.
 *
 * `notifySpace()` psalo všem členům prostoru, tedy i divákovi a přispěvateli.
 * Host si pak přes `api/v1/notifications` přečetl „X přidal/a nové médium:
 * <jméno souboru>", počty z bankovního importu i import kalendáře.
 */
class NotifySpaceJenDvojiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_host_upozorneni_prostoru_nedostane(): void
    {
        $adri = User::factory()->create(['is_active' => true]);
        $maki = User::factory()->create(['is_active' => true]);
        $divak = User::factory()->create(['is_active' => true]);
        $prispevatel = User::factory()->create(['is_active' => true]);
        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $adri->id]);
        $prostor->members()->attach($adri->id, ['role' => 'owner', 'joined_at' => now()]);
        $prostor->members()->attach($maki->id, ['role' => 'editor', 'joined_at' => now()]);
        $prostor->members()->attach($divak->id, ['role' => 'viewer', 'joined_at' => now()]);
        $prostor->members()->attach($prispevatel->id, ['role' => 'contributor', 'joined_at' => now()]);

        GalleryNotification::notifySpace($prostor, $adri->id, 'media.added', 'Adri přidal/a nové médium: IMG_0001.jpg', '/media/x');

        $this->assertSame(1, $maki->notifications()->count(), 'Partner má upozornění dostat.');
        $this->assertSame(0, $adri->notifications()->count(), 'Autor sám sobě nepíše.');
        $this->assertSame(0, $divak->notifications()->count(), 'Divák nesmí dostat upozornění dvojice.');
        $this->assertSame(0, $prispevatel->notifications()->count(), 'Přispěvatel nesmí dostat upozornění dvojice.');
    }
}
