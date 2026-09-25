<?php

namespace Tests\Feature\Memories;

use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\GeneratedMemory;
use App\Models\User;
use App\Services\Memories\MemoryGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Akce před rokem jako vzpomínka — a jen ta společná.
 *
 * `fromEvents()` filtrovalo `whereNull('deleted_at')`, jenže `calendar_events`
 * měkké mazání nemá. SQLite neznámý sloupec v uvozovkách bere jako text, takže
 * testy tiše nedostaly žádnou akci; produkční MySQL na tom padá a
 * `gallery:memories` každé ráno skončil výjimkou pro všechny prostory.
 *
 * Zároveň chyběl filtr soukromých akcí: název soukromé akce jednoho by skončil
 * v kartě celého prostoru i v upozornění partnerovi.
 */
class MemoryGeneratorEventsTest extends TestCase
{
    use RefreshDatabase;

    private const DEN = '2026-09-25';

    private GallerySpace $prostor;

    private User $adri;

    private User $maki;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['is_active' => true]);
        $this->maki = User::factory()->create(['is_active' => true]);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->attach($this->adri->id, ['role' => 'owner', 'joined_at' => now()]);
        $this->prostor->members()->attach($this->maki->id, ['role' => 'editor', 'joined_at' => now()]);
    }

    public function test_spolecna_akce_pred_rokem_je_vzpominka(): void
    {
        $this->akce('Výlet na Sněžku');

        $this->artisan('gallery:memories', ['--day' => self::DEN])->assertSuccessful();

        $karta = GeneratedMemory::where('gallery_space_id', $this->prostor->id)->where('kind', 'event')->sole();
        $this->assertSame('Výlet na Sněžku', $karta->title);
        $this->assertSame(1, (int) $karta->years_ago);
    }

    public function test_soukroma_akce_nevytvori_kartu_ani_upozorneni_partnerovi(): void
    {
        $this->akce('Výlet na Sněžku');
        $this->akce('Překvapení k výročí', ['is_private' => true]);

        $this->artisan('gallery:memories', ['--day' => self::DEN])->assertSuccessful();

        $tituly = GeneratedMemory::where('gallery_space_id', $this->prostor->id)->where('kind', 'event')->pluck('title')->all();
        $this->assertSame(['Výlet na Sněžku'], $tituly, 'Soukromá akce nesmí založit kartu pro celý prostor.');

        $zpravy = DB::table('notifications')->pluck('data')->implode("\n");
        $this->assertStringNotContainsString('Překvapení k výročí', $zpravy, 'Název soukromé akce nesmí dojít v upozornění nikomu.');
        $this->assertSame(1, $this->maki->notifications()->count(), 'Partner má dostat upozornění na společnou akci.');
    }

    public function test_zrusena_akce_neni_vzpominka(): void
    {
        $this->akce('Koncert, který se nekonal', ['status' => 'cancelled']);

        (new MemoryGeneratorService)->generate($this->prostor, Carbon::parse(self::DEN));

        $this->assertSame(0, GeneratedMemory::where('kind', 'event')->count());
    }

    /**
     * Každý sloupec, na který se dotaz akcí ptá, v tabulce opravdu je.
     *
     * Na SQLite neznámý sloupec neselže (viz popis třídy), takže to test musí
     * ověřit sám — jinak by stejná chyba prošla znovu.
     */
    public function test_dotaz_na_akce_pouziva_jen_existujici_sloupce(): void
    {
        $this->akce('Výlet na Sněžku');
        $dotazy = [];
        DB::listen(function ($dotaz) use (&$dotazy) {
            if (str_contains($dotaz->sql, '"calendar_events"')) {
                $dotazy[] = $dotaz->sql;
            }
        });

        (new MemoryGeneratorService)->generate($this->prostor, Carbon::parse(self::DEN));

        $this->assertNotEmpty($dotazy, 'Dotaz na akce se měl spustit.');
        foreach ($dotazy as $sql) {
            preg_match_all('/"([a-z_]+)"/', $sql, $shody);
            foreach (array_diff(array_unique($shody[1]), ['calendar_events']) as $sloupec) {
                $this->assertTrue(Schema::hasColumn('calendar_events', $sloupec), "Sloupec calendar_events.{$sloupec} neexistuje: {$sql}");
            }
        }
    }

    private function akce(string $nazev, array $navic = []): CalendarEvent
    {
        return CalendarEvent::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => $nazev,
            'type' => 'event',
            'status' => 'completed',
            'starts_at' => '2025-09-25 18:00:00',
            'timezone' => 'Europe/Prague',
            'is_private' => false,
        ], $navic));
    }
}
