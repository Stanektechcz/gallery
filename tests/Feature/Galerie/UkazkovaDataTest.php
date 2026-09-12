<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Úklid řádků, které zápis stavu vložil z ukázky prototypu.
 *
 * Než server posílal prázdné seznamy, poslal klient po přidání jednoho filmu
 * celý ukázkový seznam a ten skončil v `watch_titles`. Příkaz ho musí najít —
 * a zároveň nesmí sáhnout na nic, co si dvojice zapsala sama.
 */
class UkazkovaDataTest extends TestCase
{
    use RefreshDatabase;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $adri = User::factory()->create(['name' => 'Adrian']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $adri->id]);
    }

    private function film(string $nazev, CarbonImmutable $kdy): int
    {
        return DB::table('watch_titles')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'title' => $nazev,
            'created_at' => $kdy,
            'updated_at' => $kdy,
        ]);
    }

    private function rodina(string $jmeno, CarbonImmutable $kdy): int
    {
        return DB::table('couple_family_contacts')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'name' => $jmeno,
            'created_at' => $kdy,
            'updated_at' => $kdy,
        ]);
    }

    /** Bez `--smazat` se jen vypisuje — nasazení nic nesmaže omylem. */
    public function test_bez_prepinace_jen_vypise(): void
    {
        $davka = CarbonImmutable::parse('2026-09-06 20:18:14');
        $this->film('Dune: Part Two', $davka);
        $this->film('Perfect Days', $davka);

        $this->artisan('gallery:ukazkova-data')
            ->expectsOutputToContain('Z ukázky prototypu (přišlo dávkou): 2')
            ->assertSuccessful();

        $this->assertSame(2, DB::table('watch_titles')->count());
    }

    /**
     * Smaže se dávka z ukázky; vlastní tituly i osamocená shoda zůstanou.
     *
     * „Chorvatsko" nebo „Randíčko" si dvojice klidně zapíše sama. Když takový
     * řádek vznikl samostatně, není důvod ho považovat za ukázku.
     */
    public function test_smaze_jen_davku_z_ukazky(): void
    {
        $davka = CarbonImmutable::parse('2026-09-06 20:18:14');
        $ukazka = [$this->film('Dune: Part Two', $davka), $this->film('Anatomie pádu', $davka)];
        $vlastni = $this->film('Pelíšky', $davka);
        $osamocena = $this->film('Poor Things', $davka->addHour());

        $rodina = [$this->rodina('Máma, Olomouc', $davka), $this->rodina('Rodiče, Tábor', $davka)];
        $babicka = $this->rodina('Babička Jarmila', $davka);

        $this->artisan('gallery:ukazkova-data', ['--smazat' => true, '--force' => true])
            ->expectsOutputToContain('Smazáno 4 řádků z ukázky.')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('watch_titles')->whereIn('id', $ukazka)->count());
        $this->assertSame(0, DB::table('couple_family_contacts')->whereIn('id', $rodina)->count());
        $this->assertTrue(DB::table('watch_titles')->where('id', $vlastni)->exists());
        $this->assertTrue(DB::table('watch_titles')->where('id', $osamocena)->exists());
        $this->assertTrue(DB::table('couple_family_contacts')->where('id', $babicka)->exists());
    }

    /** Stejná vteřina v jiném prostoru není stejná dávka. */
    public function test_davka_se_pocita_po_prostorech(): void
    {
        $kdy = CarbonImmutable::parse('2026-09-06 20:18:14');
        $this->film('Dune: Part Two', $kdy);

        $druhy = GallerySpace::create(['name' => 'Jiná dvojice', 'owner_id' => User::factory()->create()->id]);
        DB::table('watch_titles')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $druhy->id,
            'title' => 'Perfect Days',
            'created_at' => $kdy,
            'updated_at' => $kdy,
        ]);

        $this->artisan('gallery:ukazkova-data', ['--smazat' => true, '--force' => true])
            ->expectsOutputToContain('Žádné řádky z ukázky.')
            ->assertSuccessful();

        $this->assertSame(2, DB::table('watch_titles')->count());
    }
}
