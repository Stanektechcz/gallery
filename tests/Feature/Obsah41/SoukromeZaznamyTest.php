<?php

namespace Tests\Feature\Obsah41;

use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Soukromé zápisy druhého nepatří do stop, rekonstrukcí ani počtů.
 *
 * Kalendářní událost se zapisuje i do společné stopy (`life_events`), deník
 * do rekonstrukce dne a do souhrnu roku — a nikde se nehlídalo, že je
 * soukromá, nebo dokonce smazaná.
 */
class SoukromeZaznamyTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->maki = User::factory()->create(['name' => 'Makinka']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);

        Sanctum::actingAs($this->adri);
    }

    public function test_stopa_neprozradi_partnerovu_soukromou_udalost(): void
    {
        $this->udalost($this->maki, 'Její tajná schůzka', true);
        $this->udalost($this->adri, 'Moje soukromé', true);
        $this->udalost($this->maki, 'Společná večeře', false);

        $vety = collect($this->getJson('/api/data/planovani')->assertOk()->json('data.EVSEED'))->pluck(0)->implode(' | ');

        $this->assertStringNotContainsString('Její tajná schůzka', $vety);
        $this->assertStringContainsString('Moje soukromé', $vety);
        $this->assertStringContainsString('Společná večeře', $vety);
    }

    public function test_rekonstrukce_dne_bez_ciziho_soukromeho_a_smazaneho_zapisu(): void
    {
        $this->fotka('2026-05-02 10:00:00', 1);
        $this->fotka('2026-05-02 15:00:00', 2);
        $this->zapis($this->maki, 'Její soukromý', '2026-05-02', 'private');
        $this->zapis($this->adri, 'Můj soukromý', '2026-05-02', 'private');
        $this->zapis($this->adri, 'Smazaný', '2026-05-02', 'shared', ['deleted_at' => now()]);
        $this->transakce('Kavárna', '2026-05-02 09:00:00');
        $this->transakce('Směna na eura', '2026-05-02 09:30:00', ['type' => 'exchange']);
        $this->transakce('Smazaná platba', '2026-05-02 09:45:00', ['deleted_at' => now()]);

        $kroky = collect($this->getJson('/api/data/pribeh')->assertOk()->json('data.RECON.2026-05-02.steps'))
            ->pluck(1)->implode(' | ');

        $this->assertStringContainsString('Můj soukromý', $kroky);
        $this->assertStringContainsString('Kavárna', $kroky);
        $this->assertStringNotContainsString('Její soukromý', $kroky);
        $this->assertStringNotContainsString('Smazaný', $kroky);
        $this->assertStringNotContainsString('Směna na eura', $kroky);
        $this->assertStringNotContainsString('Smazaná platba', $kroky);
    }

    public function test_souhrn_roku_a_kapitola_nepocitaji_cizi_soukrome_ani_smazane_zapisy(): void
    {
        DB::table('couple_story_chapters')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
            'title' => 'Rok 2025', 'year' => '2025', 'status' => 'draft', 'body' => '', 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->fotka('2025-06-21 10:00:00', 1);
        $this->zapis($this->adri, 'Sdílený', '2025-03-01', 'shared');
        $this->zapis($this->adri, 'Můj soukromý', '2025-03-02', 'private');
        $this->zapis($this->maki, 'Její soukromý', '2025-03-03', 'private');
        $this->zapis($this->maki, 'Smazaný', '2025-03-04', 'shared', ['deleted_at' => now()]);

        $data = $this->getJson('/api/data/pribeh')->assertOk()->json('data');

        $this->assertSame(2, $data['STORY_ROKY']['2025']['zapisu']);
        $this->assertSame(2, $data['STORY'][0][5]);
    }

    /** „Večer mimo domov" nesmí prozradit, že partner měl soukromou akci. */
    public function test_vecer_mimo_domov_bez_ciziho_soukromeho_terminu(): void
    {
        DB::table('wellbeing_moods')->insert([
            'gallery_space_id' => $this->prostor->id, 'user_id' => $this->adri->id,
            'day' => $this->dnes()->toDateString(), 'value' => 3, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $vecer = $this->dnes()->setTime(19, 0);
        DB::table('calendar_events')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->maki->id,
            'title' => 'Tajné', 'type' => 'event', 'status' => 'confirmed', 'is_private' => true,
            'starts_at' => $vecer->utc(), 'ends_at' => $vecer->setTime(22, 0)->utc(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $druhy = collect($this->getJson('/api/data/zdravi')->assertOk()->json('data.KL_EV'))->pluck('kind')->all();

        $this->assertNotContains('Večer mimo domov', $druhy);
    }

    // ——— pomůcky ———

    private function udalost(User $kdo, string $nazev, bool $soukroma): void
    {
        CalendarEvent::create([
            'gallery_space_id' => $this->prostor->id, 'created_by' => $kdo->id, 'title' => $nazev,
            'type' => 'event', 'status' => 'confirmed', 'is_private' => $soukroma,
            'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour(),
        ]);
    }

    private function zapis(User $kdo, string $nazev, string $den, string $viditelnost, array $navic = []): void
    {
        DB::table('journal_entries')->insert(array_merge([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $kdo->id,
            'title' => $nazev, 'body' => 'Text.', 'entry_date' => $den, 'visibility' => $viditelnost,
            'created_at' => $den.' 12:00:00', 'updated_at' => now(),
        ], $navic));
    }

    private function transakce(string $popis, string $kdy, array $navic = []): void
    {
        DB::table('transactions')->insert(array_merge([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
            'type' => 'expense', 'occurred_at' => $kdy, 'amount_from' => 120, 'currency_from' => 'CZK',
            'description' => $popis, 'created_at' => now(), 'updated_at' => now(),
        ], $navic));
    }

    private function fotka(string $kdy, int $poradi): MediaItem
    {
        return MediaItem::create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id, 'uploaded_by' => $this->adri->id,
            'original_filename' => 'IMG_'.$poradi.'.jpg', 'safe_filename' => 'img-'.$poradi.'.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 2048,
            'uploaded_at' => now(), 'status' => 'ready', 'storage_status' => 'local', 'taken_at' => $kdy,
        ]);
    }
}
