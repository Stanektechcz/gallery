<?php

namespace Tests\Feature\Notifications;

use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\User;
use App\Notifications\EventReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Čas v upozorněních plánu — jak ho člověk zapsal.
 *
 * Začátek akce i termín úkolu se ukládají podle pražských hodin (viz
 * `App\Support\Cas`), takže se v textu nepřevádějí. Formát termínu úkolu
 * obsahoval holé `v`, které PHP čte jako milisekundy: „25. 9. 000 16:00".
 */
class CasVUpozornenichPlanuTest extends TestCase
{
    use RefreshDatabase;

    private GallerySpace $prostor;

    private User $adri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['is_active' => true, 'name' => 'Adri']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->attach($this->adri->id, ['role' => 'owner', 'joined_at' => now()]);
    }

    public function test_termin_ukolu_je_v_hodinach_bez_milisekund(): void
    {
        $akce = $this->akce('Oslava', now()->addDays(2));
        $termin = CarbonImmutable::parse(now()->addHours(3)->format('Y-m-d H:i:00'));
        DB::table('event_tasks')->insert([
            'event_id' => $akce->id,
            'assigned_to' => $this->adri->id,
            'title' => 'Koupit dort',
            'due_at' => $termin->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('gallery:planning-followups')->assertSuccessful();

        $zprava = (string) ($this->adri->notifications()->sole()->data['message'] ?? '');
        $this->assertStringContainsString('termín '.$termin->format('j. n.').' v '.$termin->format('H:i'), $zprava);
        $this->assertStringNotContainsString(' 000 ', $zprava);
    }

    public function test_e_mail_pripominky_ukaze_zacatek_jak_byl_zapsan(): void
    {
        $akce = $this->akce('Koncert', CarbonImmutable::parse('2026-07-10 18:00:00'));

        $mail = (new EventReminderNotification($akce, 'email'))->toMail($this->adri);

        $this->assertContains('Začátek: 10. července 2026, 18:00', $mail->introLines);
    }

    private function akce(string $nazev, CarbonImmutable|\DateTimeInterface $zacatek): CalendarEvent
    {
        return CalendarEvent::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => $nazev,
            'type' => 'event',
            'status' => 'planned',
            'starts_at' => $zacatek,
            'timezone' => 'Europe/Prague',
            'is_private' => false,
        ]);
    }
}
