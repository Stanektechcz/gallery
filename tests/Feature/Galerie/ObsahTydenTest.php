<?php

namespace Tests\Feature\Galerie;

use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\JournalEntry;
use App\Models\MediaItem;
use App\Models\SharedTodo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Týdenní přehled ze skutečných dat.
 *
 * Obrazovka byla celá napsaná v kódu — 196 fotek, 11 z 16 úkolů, Pustevny nad
 * mlhou a pension v Sintře po termínu. Tatáž čísla chodila v upozornění
 * a na úvodní obrazovce.
 */
class ObsahTydenTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        // Středa: týden má za sebou pondělí i úterý a před sebou víkend.
        CarbonImmutable::setTestNow('2026-09-16 12:00:00');
        Carbon::setTestNow('2026-09-16 12:00:00');

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->maki = User::factory()->create(['name' => 'Markéta']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);

        Sanctum::actingAs($this->adri);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_prazdny_tyden_rika_ze_je_prazdny(): void
    {
        $tyden = $this->getJson('/api/data/tyden')->assertOk()->json('data.WEEK');

        $this->assertSame('Týden 14. – 20. září 2026', $tyden['now']['title']);
        $this->assertStringContainsString('prázdný', $tyden['now']['lead']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $tyden['now']['days']);
        $this->assertSame('0', $tyden['now']['stats'][0][1]);
        $this->assertSame([], $tyden['now']['moments']);
        $this->assertSame([], $tyden['next']['rows']);
        $this->assertCount(4, $tyden['past']['rows']);
    }

    public function test_fotky_po_dnech_a_momenty_jsou_z_knihovny(): void
    {
        foreach (range(1, 3) as $i) {
            $this->fotka(['taken_at' => '2026-09-15 10:0'.$i.':00', 'location_name' => 'Pálava']);
        }
        $this->fotka(['taken_at' => '2026-09-14 18:00:00']);
        // Minulý týden a koš se do tohoto týdne nepočítají.
        $this->fotka(['taken_at' => '2026-09-10 10:00:00']);
        $this->fotka(['taken_at' => '2026-09-15 11:00:00', 'trashed_at' => now()]);

        $tyden = $this->getJson('/api/data/tyden')->assertOk()->json('data.WEEK');

        $this->assertSame([1, 3, 0, 0, 0, 0, 0], $tyden['now']['days']);
        $this->assertSame('4', $tyden['now']['stats'][0][1]);
        $this->assertSame('+300 % proti minulému týdnu', $tyden['now']['stats'][0][2]);
        $this->assertSame('Pálava', $tyden['now']['moments'][0]['title']);
        $this->assertSame('úterý · 3 fotky', $tyden['now']['moments'][0]['meta']);
        $this->assertStringContainsString('v úterý', $tyden['now']['daysNote']);
        $this->assertSame(1, $tyden['past']['rows'][0]['photos']);
    }

    public function test_ukoly_po_terminu_a_hotove(): void
    {
        $this->ukol(['title' => 'Zaplatit zálohu', 'due_at' => '2026-09-15 18:00:00']);
        $this->ukol(['title' => 'Koupit dárek', 'due_at' => '2026-09-18 18:00:00', 'status' => 'completed', 'completed_at' => '2026-09-16 09:00:00']);
        $this->ukol(['title' => 'Zrušené', 'due_at' => '2026-09-14 18:00:00', 'status' => 'cancelled']);
        $this->ukol(['title' => 'Příští týden', 'due_at' => '2026-09-22 10:00:00', 'assigned_to' => $this->maki->id]);

        $tyden = $this->getJson('/api/data/tyden')->assertOk()->json('data.WEEK');

        $this->assertSame('1 z 2', $tyden['now']['stats'][2][1]);
        $this->assertSame(['Zaplatit zálohu', 'termín byl včera', 'x-plan'], $tyden['now']['slipped'][0]);
        $this->assertCount(1, $tyden['now']['slipped']);
        $this->assertSame(['Úterý 22. 9.', 'Příští týden', 'Markéta', 'ph-check-square'], $tyden['next']['rows'][0]);
    }

    public function test_pristi_tyden_je_z_kalendare_bez_soukromych(): void
    {
        $this->udalost(['title' => 'Kino', 'type' => 'outing', 'starts_at' => '2026-09-23 19:30:00']);
        $this->udalost(['title' => 'Tajné', 'starts_at' => '2026-09-24 19:30:00', 'is_private' => true]);
        $this->udalost(['title' => 'Letos už bylo', 'starts_at' => '2026-09-15 19:30:00']);

        $radky = $this->getJson('/api/data/tyden')->assertOk()->json('data.WEEK.next.rows');

        $this->assertSame([['Středa 23. 9.', 'Kino', 'Adrian', 'ph-film-slate']], $radky);
    }

    /** Cizí soukromý zápis se nepočítá — prozradil by, že si druhý něco píše. */
    public function test_cizi_soukromy_zapis_se_nepocita(): void
    {
        $this->zapis($this->adri, 'private');
        $this->zapis($this->maki, 'private');
        $this->zapis($this->maki, 'shared');

        $zapisy = $this->getJson('/api/data/tyden')->assertOk()->json('data.WEEK.now.stats.1');

        $this->assertSame('2', $zapisy[1]);
    }

    public function test_tyden_je_uplna_kolekce(): void
    {
        $this->assertContains('WEEK', $this->getJson('/api/data/tyden')->assertOk()->json('uplne'));
    }

    // ——— pomůcky ———

    private function fotka(array $navic = []): MediaItem
    {
        static $poradi = 0;
        $poradi++;

        return MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'IMG_'.$poradi.'.jpg',
            'safe_filename' => 'img-'.$poradi.'.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 2_097_152,
            'width' => 4032,
            'height' => 3024,
            'uploaded_at' => '2026-09-16 09:00:00',
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }

    private function ukol(array $navic): SharedTodo
    {
        return SharedTodo::create(array_merge([
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Úkol',
            'status' => 'open',
            'priority' => 'normal',
        ], $navic));
    }

    private function udalost(array $navic): CalendarEvent
    {
        return CalendarEvent::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Událost',
            'type' => 'event',
            'status' => 'planned',
            'timezone' => 'Europe/Prague',
        ], $navic));
    }

    private function zapis(User $kdo, string $viditelnost): JournalEntry
    {
        return JournalEntry::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $kdo->id,
            'title' => 'Zápis',
            'body' => 'Text',
            'entry_date' => '2026-09-15',
            'visibility' => $viditelnost,
        ]);
    }
}
