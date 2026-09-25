<?php

namespace Tests\Feature\Notifications;

use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\User;
use App\Notifications\EventReminderNotification;
use App\Services\Notifications\WebPushService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Čas akce v připomínkách — podle toho, jak je který začátek uložený.
 *
 * Běžná akce má `starts_at` v pražských hodinách (jak ho člověk zadal), push
 * ho ale převáděl `->timezone('Europe/Prague')` a akci v 18:00 hlásil na 20:00.
 * Promítání z Cinema City se naopak ukládá jako okamžik v UTC — to se převést
 * musí, jinak by push i e-mail ukázaly o dvě hodiny dřív.
 */
class CasPripominekAkciTest extends TestCase
{
    use RefreshDatabase;

    private GallerySpace $prostor;

    private User $adri;

    /** @var list<array<string, mixed>> */
    private array $pushe = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['is_active' => true, 'name' => 'Adri']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->attach($this->adri->id, ['role' => 'owner', 'joined_at' => now()]);

        $this->mock(WebPushService::class, function ($mock) {
            $mock->shouldReceive('sendToUser')->andReturnUsing(function (User $komu, array $zprava) {
                $this->pushe[] = $zprava;

                return 1;
            });
        });
    }

    /** @return array<string, array{string, string}> */
    public static function obdobi(): array
    {
        return [
            'léto' => ['2026-07-10 18:00:00', '10. 7. 18:00'],
            'zima' => ['2026-01-15 18:00:00', '15. 1. 18:00'],
        ];
    }

    #[DataProvider('obdobi')]
    public function test_push_ukaze_zacatek_akce_jak_byl_zapsan(string $zapsano, string $ocekavano): void
    {
        $this->travelTo(Carbon::parse($zapsano, 'UTC')->subDay());
        $akce = $this->akce($zapsano);
        $this->pripominka($akce);

        $this->artisan('gallery:deliver-reminders')->assertSuccessful();

        $this->assertCount(1, $this->pushe);
        $this->assertStringStartsWith($ocekavano, $this->pushe[0]['body']);
    }

    public function test_promitani_z_kina_se_prevede_z_utc(): void
    {
        // Promítání v 20:00 pražského času je v UTC uložené jako 18:00.
        $this->travelTo(Carbon::parse('2026-07-09 12:00:00', 'UTC'));
        $akce = $this->akce('2026-07-10 18:00:00');
        $this->zKina($akce);
        $this->pripominka($akce);

        $this->artisan('gallery:deliver-reminders')->assertSuccessful();

        $this->assertStringStartsWith('10. 7. 20:00', $this->pushe[0]['body']);
        $mail = (new EventReminderNotification($akce->fresh(), 'email'))->toMail($this->adri);
        $this->assertContains('Začátek: 10. července 2026, 20:00', $mail->introLines);
    }

    private function akce(string $zacatek): CalendarEvent
    {
        return CalendarEvent::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Kino',
            'type' => 'event',
            'status' => 'planned',
            'starts_at' => $zacatek,
            'timezone' => 'Europe/Prague',
            'is_private' => false,
        ]);
    }

    private function pripominka(CalendarEvent $akce): void
    {
        DB::table('event_reminders')->insert([
            'event_id' => $akce->id,
            'user_id' => $this->adri->id,
            'channel' => 'push',
            'remind_at' => now()->subMinute(),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function zKina(CalendarEvent $akce): void
    {
        $titul = DB::table('entertainment_titles')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'added_by' => $this->adri->id,
            'media_type' => 'movie',
            'title' => 'Film',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $promitani = DB::table('cinema_showings')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'cinema_code' => '1052',
            'cinema_name' => 'Cinema City',
            'external_event_id' => 'e-1',
            'title' => 'Film',
            'starts_at' => '2026-07-10 18:00:00',
            'fetched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('viewing_date_proposals')->insert([
            'uuid' => (string) Str::uuid(),
            'entertainment_title_id' => $titul,
            'proposed_by' => $this->adri->id,
            'cinema_showing_id' => $promitani,
            'calendar_event_id' => $akce->id,
            'starts_at' => '2026-07-10 18:00:00',
            'venue' => 'cinema',
            'status' => 'selected',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
