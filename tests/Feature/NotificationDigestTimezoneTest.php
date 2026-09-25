<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Večerní souhrn nesmí přehlédnout první dvě hodiny pražského dne.
 *
 * `$od = Cas::dnes()` dá půlnoc jako UTC datum (kvůli řazení s DATE sloupci),
 * ne skutečný okamžik, kdy dnešek v Praze začal. Upozornění z 22:00-24:00 UTC
 * předchozího dne — tedy z 00:00-02:00 pražského času dneška — tak do
 * souhrnu nikdy nespadlo.
 */
class NotificationDigestTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_upozorneni_tesne_po_pulnoci_v_praze_se_dostane_do_souhrnu(): void
    {
        $this->travelTo(Carbon::parse('2026-09-25 18:30:00', 'UTC'));

        $user = User::factory()->create([
            'preferences' => ['notifications' => ['digest' => true]],
        ]);

        // 22:30 UTC dne 24. 9. je 00:30 v Praze dne 25. 9. — dnešek dvojice,
        // i když je to ještě včerejší UTC datum.
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\GalleryNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['category_label' => 'Ostatní']),
            'read_at' => null,
            'created_at' => '2026-09-24 22:30:00',
            'updated_at' => '2026-09-24 22:30:00',
        ]);

        $this->artisan('gallery:notification-digest')->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
            'type' => 'App\\Notifications\\GalleryNotification',
        ]);
        $this->assertSame(2, DB::table('notifications')->where('notifiable_id', $user->id)->count(),
            'Souhrn se měl poslat jako druhá zpráva navíc k té z 00:30.');
    }
}
