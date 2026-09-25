<?php

namespace Tests\Feature\Meny;

use App\Services\Finance\ExchangeRateService;
use App\Services\Integrations\FreeTravelDataService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Součet přes měny v hlavní měně (CZK) kurzem ECB.
 *
 * Kurz je snímek jednoho dne, proto se vrací i datum. A když některý kurz
 * chybí, smíšené číslo se neukáže vůbec — polovičatý součet by vypadal stejně
 * důvěryhodně jako úplný.
 */
class PrepocetDoHlavniTest extends TestCase
{
    private function sluzba(): ExchangeRateService
    {
        return app(ExchangeRateService::class);
    }

    /** @param  array<string, array{rate: float, date: string}|null>  $kurzy  měna => kurz do CZK (null = výpadek) */
    private function kurzy(array $kurzy): void
    {
        $this->mock(FreeTravelDataService::class, function ($mock) use ($kurzy) {
            foreach ($kurzy as $mena => $kurz) {
                $ocekavani = $mock->shouldReceive('rate')
                    ->with($mena, 'CZK', null, Mockery::on(fn ($cekat) => is_int($cekat) && $cekat <= 3));

                $kurz === null
                    ? $ocekavani->andThrow(new \RuntimeException('mimo provoz'))
                    : $ocekavani->andReturn(['date' => $kurz['date'], 'base' => $mena, 'quote' => 'CZK', 'rate' => $kurz['rate']]);
            }
        });
    }

    public function test_jen_koruny_se_jen_sectou_a_kurz_se_nehleda(): void
    {
        $this->mock(FreeTravelDataService::class, function ($mock) {
            $mock->shouldReceive('rate')->never();
        });

        $vysledek = $this->sluzba()->doHlavni(['CZK' => 1000, 'czk' => 250.5]);

        $this->assertSame([
            'mena' => 'CZK',
            'celkem' => 1250.5,
            'uplne' => true,
            'prepocteno' => false,
            'kurzKeDni' => null,
            'kurzy' => [],
            'poMenach' => ['CZK' => 1250.5],
            'chybi' => [],
        ], $vysledek);
        $this->assertNull($this->sluzba()->popisek($vysledek));
    }

    public function test_prazdny_vstup_je_nula_a_uplny(): void
    {
        $this->mock(FreeTravelDataService::class, function ($mock) {
            $mock->shouldReceive('rate')->never();
        });

        $vysledek = $this->sluzba()->doHlavni([]);

        $this->assertSame(0.0, $vysledek['celkem']);
        $this->assertTrue($vysledek['uplne']);
        $this->assertFalse($vysledek['prepocteno']);
        $this->assertSame([], $vysledek['poMenach']);
        $this->assertSame([], $vysledek['chybi']);

        // Samé nuly jsou totéž co nic — kvůli nulové položce se kurz nehledá.
        $this->assertSame(0.0, $this->sluzba()->doHlavni(['EUR' => 0.001, 'CZK' => 0])['celkem']);
    }

    public function test_koruny_a_eura_se_sectou_v_korunach_s_datem_kurzu(): void
    {
        $this->kurzy(['EUR' => ['rate' => 25.0, 'date' => '2026-09-24']]);

        $vysledek = $this->sluzba()->doHlavni(['CZK' => 1000, 'EUR' => 100]);

        $this->assertSame(3500.0, $vysledek['celkem']);
        $this->assertTrue($vysledek['uplne']);
        $this->assertTrue($vysledek['prepocteno']);
        $this->assertSame('2026-09-24', $vysledek['kurzKeDni']);
        $this->assertSame(['EUR' => 25.0], $vysledek['kurzy']);
        $this->assertSame(['CZK' => 1000.0, 'EUR' => 100.0], $vysledek['poMenach']);
        $this->assertSame([], $vysledek['chybi']);
        $this->assertSame('přepočteno kurzem ECB k 24. 9. 2026', $this->sluzba()->popisek($vysledek));
    }

    public function test_chybejici_kurz_nedava_smisene_cislo(): void
    {
        $this->kurzy([
            'EUR' => ['rate' => 25.0, 'date' => '2026-09-24'],
            'USD' => null,
        ]);

        $vysledek = $this->sluzba()->doHlavni(['CZK' => 1000, 'EUR' => 100, 'USD' => 50]);

        $this->assertNull($vysledek['celkem']);
        $this->assertFalse($vysledek['uplne']);
        $this->assertFalse($vysledek['prepocteno']);
        $this->assertSame(['USD'], $vysledek['chybi']);
        $this->assertSame(['CZK' => 1000.0, 'EUR' => 100.0, 'USD' => 50.0], $vysledek['poMenach']);
        $this->assertSame(['EUR' => 25.0], $vysledek['kurzy']);
        $this->assertNull($this->sluzba()->popisek($vysledek));
    }

    /**
     * Při výpadku se nečeká na každou měnu zvlášť: po prvním neúspěšném
     * dotazu se další měny v témže součtu už nezkoušejí, jinak by dvě měny
     * znamenaly dvakrát čekání na odpověď.
     */
    public function test_po_vypadku_se_dalsi_mena_nezkousi(): void
    {
        $this->mock(FreeTravelDataService::class, function ($mock) {
            $mock->shouldReceive('rate')->once()->andThrow(new \RuntimeException('mimo provoz'));
        });

        $vysledek = $this->sluzba()->doHlavni(['EUR' => 10, 'USD' => 10]);

        $this->assertNull($vysledek['celkem']);
        $this->assertSame(['EUR', 'USD'], $vysledek['chybi']);
    }

    public function test_zaokrouhleni_na_halere(): void
    {
        $this->kurzy([
            'EUR' => ['rate' => 24.337, 'date' => '2026-09-24'],
            'USD' => ['rate' => 21.1234, 'date' => '2026-09-23'],
        ]);

        $vysledek = $this->sluzba()->doHlavni(['EUR' => 10.004, 'eur' => 0.333, 'USD' => 1.115, 'CZK' => 0.1]);

        // Po měnách na dvě místa: EUR 10.337 → 10.34, USD 1.115 → 1.12 (poloviny nahoru).
        $this->assertSame(['EUR' => 10.34, 'USD' => 1.12, 'CZK' => 0.1], $vysledek['poMenach']);
        // 0.1 + 10.34 × 24.337 + 1.12 × 21.1234 = 0.1 + 251.64458 + 23.658208 = 275.402788
        $this->assertSame(275.4, $vysledek['celkem']);
        // Souhrn není čerstvější než jeho nejstarší kurz.
        $this->assertSame('2026-09-23', $vysledek['kurzKeDni']);
    }

    public function test_male_klice_se_slouci_s_velkymi(): void
    {
        $this->kurzy(['EUR' => ['rate' => 25.0, 'date' => '2026-09-24']]);

        $vysledek = $this->sluzba()->doHlavni(['eur' => 40, 'EUR' => 60, ' Eur ' => 1]);

        $this->assertSame(['EUR' => 101.0], $vysledek['poMenach']);
        $this->assertSame(2525.0, $vysledek['celkem']);
    }

    public function test_kurz_se_na_den_zapamatuje_a_druhy_soucet_nejde_na_sit(): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(['date' => '2026-09-24', 'base' => 'EUR', 'quote' => 'CZK', 'rate' => 25.0]),
        ]);

        $prvni = $this->sluzba()->doHlavni(['CZK' => 1000, 'EUR' => 100]);
        $druhy = $this->sluzba()->doHlavni(['EUR' => 2]);

        $this->assertSame(3500.0, $prvni['celkem']);
        $this->assertSame(50.0, $druhy['celkem']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $pozadavek) => str_contains($pozadavek->url(), 'api.frankfurter.dev/v2/rate/EUR/CZK'));
    }

    public function test_bez_podvrzeneho_kurzu_testy_na_sit_nesahaji(): void
    {
        // Výchozí stav testů: žádný kurz není známý, dokud si ho test nepodvrhne.
        $vysledek = $this->sluzba()->doHlavni(['CZK' => 1, 'EUR' => 1]);

        $this->assertNull($vysledek['celkem']);
        $this->assertSame(['EUR'], $vysledek['chybi']);
    }

    public function test_neznamy_kod_se_neprepocita(): void
    {
        $this->mock(FreeTravelDataService::class, function ($mock) {
            $mock->shouldReceive('rate')->never();
        });

        $vysledek = $this->sluzba()->doHlavni(['CZK' => 5, 'E1' => 3]);

        $this->assertNull($vysledek['celkem']);
        $this->assertSame(['E1'], $vysledek['chybi']);
        $this->assertSame(['CZK' => 5.0, 'E1' => 3.0], $vysledek['poMenach']);
    }

    public function test_combine_zustava(): void
    {
        $this->kurzy(['EUR' => ['rate' => 25.0, 'date' => '2026-09-24']]);

        $this->assertSame(
            ['total' => 3500.0, 'currency' => 'CZK', 'date' => '2026-09-24', 'rates' => ['CZK' => 1.0, 'EUR' => 25.0]],
            $this->sluzba()->combine(['CZK' => 1000, 'EUR' => 100], 'CZK'),
        );
        $this->assertNull($this->sluzba()->combine(['CZK' => 1000], 'CZK'));
    }
}
