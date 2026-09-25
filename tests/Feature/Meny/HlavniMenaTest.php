<?php

namespace Tests\Feature\Meny;

use App\Models\FinanceSettings;
use App\Models\GallerySpace;
use App\Models\User;
use App\Support\Meny;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Hlavní měna prostoru a znaky měn.
 *
 * Dvojice rozhodla: hlavní měna je CZK, další jsou EUR a USD. Čtení hlavní
 * měny nesmí zakládat řádek předvoleb — obrazovka, která se jen dívá, by
 * jinak při každém otevření něco zapisovala.
 */
class HlavniMenaTest extends TestCase
{
    use RefreshDatabase;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $adrian = User::factory()->create(['name' => 'Adrian', 'role' => 'owner', 'is_active' => true]);

        $this->prostor = GallerySpace::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Naše galerie',
            'slug' => 'nase-galerie',
            'owner_id' => $adrian->id,
            'is_default' => true,
        ]);
    }

    public function test_bez_predvoleb_je_hlavni_czk_a_nic_se_nezalozi(): void
    {
        $this->assertSame('CZK', Meny::hlavni($this->prostor));
        $this->assertSame('CZK', Meny::hlavni((int) $this->prostor->id));
        $this->assertSame('CZK', Meny::hlavni(null));

        $this->assertSame(0, DB::table('finance_settings')->count(), 'Čtení hlavní měny nesmí založit předvolby.');
    }

    public function test_nabizene_meny_zacinaji_hlavni(): void
    {
        $this->assertSame('CZK', Meny::HLAVNI);
        $this->assertSame(['CZK', 'EUR', 'USD'], Meny::NABIZENE);
    }

    public function test_predvolba_malymi_pismeny_se_cte_velkymi(): void
    {
        FinanceSettings::proProstor((int) $this->prostor->id)->update(['home_currency' => 'eur']);

        $this->assertSame('EUR', Meny::hlavni($this->prostor));
    }

    public function test_nesmyslna_predvolba_spadne_na_czk(): void
    {
        FinanceSettings::proProstor((int) $this->prostor->id)->update(['home_currency' => 'e1']);
        $this->assertSame('CZK', Meny::hlavni($this->prostor));

        FinanceSettings::query()->where('gallery_space_id', $this->prostor->id)->update(['home_currency' => '']);
        $this->assertSame('CZK', Meny::hlavni($this->prostor));
    }

    public function test_znaky_men(): void
    {
        $this->assertSame('Kč', Meny::znak('CZK'));
        $this->assertSame('Kč', Meny::znak('czk'));
        $this->assertSame('€', Meny::znak('EUR'));
        $this->assertSame('$', Meny::znak('USD'));
        $this->assertSame('£', Meny::znak('GBP'));
        $this->assertSame('CHF', Meny::znak('chf'));
        // Položka bez měny je v aplikaci koruna (tak ji četly i obrazovky cest).
        $this->assertSame('Kč', Meny::znak(''));
        $this->assertSame('Kč', Meny::znak(null));
    }

    public function test_castka_po_cesku(): void
    {
        $this->assertSame('1 500 Kč', Meny::castka(1500, 'CZK'));
        $this->assertSame('12 345,50 €', Meny::castka(12345.5, 'EUR', 2));
        $this->assertSame('-1 500 $', Meny::castka(-1500, 'usd'));
        $this->assertSame('0 Kč', Meny::castka(-0.2, 'CZK'));
    }
}
