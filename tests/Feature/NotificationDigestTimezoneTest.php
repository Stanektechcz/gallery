<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\GalleryNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        // i když je to ještě včerejší UTC datum. Skutečné upozornění, ne ručně
        // vložený řádek: ten dřív nesl `category_label`, které skutečná
        // upozornění nemají, a zakrýval tak, že souhrn psal jen „Ostatní".
        $this->travelTo(Carbon::parse('2026-09-24 22:30:00', 'UTC'));
        $user->notify(new GalleryNotification('calendar.task.due_soon', 'Brzy je potřeba dokončit: Nákup.'));
        $this->travelTo(Carbon::parse('2026-09-25 18:30:00', 'UTC'));

        $this->artisan('gallery:notification-digest')->assertSuccessful();

        $this->assertSame(2, DB::table('notifications')->where('notifiable_id', $user->id)->count(),
            'Souhrn se měl poslat jako druhá zpráva navíc k té z 00:30.');
        $souhrn = DB::table('notifications')->where('notifiable_id', $user->id)->get()
            ->map(fn ($radek) => json_decode($radek->data, true))
            ->firstWhere('type', 'system.digest');
        $this->assertStringContainsString('Plány a úkoly (1)', (string) ($souhrn['message'] ?? ''));
    }
}
