<?php

namespace Tests\Feature\Obsah41;

use App\Models\Budget;
use App\Models\FinanceAccess;
use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Auth\PristupDoGalerie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Kdo tvoří dvojici v TOMHLE prostoru — a v jakém pořadí ji obrazovka dostane.
 *
 * `dvojice()` posuzovala člena jeho PRVNÍM prostorem: partner, který je jinde
 * hostem (a ta galerie je u něj první), z vlastní dvojice vypadl.
 */
class DvojiceProstoruTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_ktery_je_jinde_hostem_zustava_ve_dvojici(): void
    {
        $cizi = User::factory()->create(['is_active' => true]);
        $ciziProstor = $this->prostor($cizi, 'Cizí', true);
        $ja = User::factory()->create(['is_active' => true]);
        $partner = User::factory()->create(['is_active' => true]);

        // Cizí galerie má nižší id i příznak výchozí → u partnera je první.
        $ciziProstor->members()->attach($cizi->id, ['role' => 'owner', 'joined_at' => now()]);
        $ciziProstor->members()->attach($partner->id, ['role' => 'viewer', 'joined_at' => now()]);

        $nas = $this->prostor($ja, 'Náš', true);
        $nas->members()->attach($ja->id, ['role' => 'owner', 'joined_at' => now()]);
        $nas->members()->attach($partner->id, ['role' => 'editor', 'joined_at' => now()]);

        $this->assertSame((int) $ciziProstor->id, (int) $partner->gallerySpaces()->first()->id);
        $this->assertEqualsCanonicalizing(
            [$ja->id, $partner->id],
            app(PristupDoGalerie::class)->dvojice($nas->fresh())->pluck('id')->all(),
        );
    }

    public function test_odebrany_ucet_a_host_do_dvojice_nepatri(): void
    {
        $ja = User::factory()->create(['is_active' => true]);
        $odebrany = User::factory()->create(['is_active' => false]);
        $host = User::factory()->create(['is_active' => true]);
        $nas = $this->prostor($ja, 'Náš', true);
        $nas->members()->attach($ja->id, ['role' => 'owner', 'joined_at' => now()]);
        $nas->members()->attach($odebrany->id, ['role' => 'editor', 'joined_at' => now()]);
        $nas->members()->attach($host->id, ['role' => 'viewer', 'joined_at' => now()]);

        $this->assertSame([$ja->id], app(PristupDoGalerie::class)->dvojice($nas->fresh())->pluck('id')->all());
    }

    public function test_dvojice_od_divaka_zacina_divakem_a_pak_podle_vstupu(): void
    {
        $ja = User::factory()->create(['is_active' => true]);
        $host = User::factory()->create(['is_active' => true]);
        $partner = User::factory()->create(['is_active' => true]);
        $nas = $this->prostor($ja, 'Náš', true);
        // Host je v prostoru dřív než partner — pořadí podle vstupu ho nesmí povýšit.
        $nas->members()->attach($host->id, ['role' => 'viewer', 'joined_at' => now()->subDays(3)]);
        $nas->members()->attach($partner->id, ['role' => 'editor', 'joined_at' => now()->subDays(2)]);
        $nas->members()->attach($ja->id, ['role' => 'owner', 'joined_at' => now()->subDay()]);

        $pristup = app(PristupDoGalerie::class);

        $this->assertSame([$ja->id, $partner->id], $pristup->dvojiceOdDivaka($nas->fresh(), $ja)->pluck('id')->all());
        $this->assertSame([$partner->id, $ja->id], $pristup->dvojiceOdDivaka($nas->fresh(), $partner)->pluck('id')->all());
        // Konzole (bez diváka) — jen podle vstupu do prostoru.
        $this->assertSame([$partner->id, $ja->id], $pristup->dvojiceOdDivaka($nas->fresh(), null)->pluck('id')->all());
    }

    public function test_viditelne_rozpocty_pro_divaka_a_konzoli(): void
    {
        $ja = User::factory()->create(['is_active' => true]);
        $partner = User::factory()->create(['is_active' => true]);
        $nas = $this->prostor($ja, 'Náš', true);
        $jiny = $this->prostor($partner, 'Jiný', false);

        $spolecny = $this->rozpocet($nas, null, 'Společný');
        $muj = $this->rozpocet($nas, $ja->id, 'Můj');
        $jeho = $this->rozpocet($nas, $partner->id, 'Jeho');
        $nasdileny = $this->rozpocet($nas, $partner->id, 'Nasdílený');
        $smazany = $this->rozpocet($nas, null, 'Smazaný');
        $smazany->delete();
        $cizi = $this->rozpocet($jiny, null, 'Cizí prostor');
        FinanceAccess::create(['gallery_space_id' => $nas->id, 'subject_type' => 'budget', 'subject_id' => $nasdileny->id, 'user_id' => $ja->id, 'can_edit' => false]);

        $this->assertEqualsCanonicalizing(
            [$spolecny->id, $muj->id, $nasdileny->id],
            FinanceAccess::viditelneRozpocty((int) $nas->id, (int) $ja->id),
        );
        $this->assertSame([$spolecny->id], FinanceAccess::viditelneRozpocty((int) $nas->id, null));
        $this->assertNotContains($jeho->id, FinanceAccess::viditelneRozpocty((int) $nas->id, (int) $ja->id));
        $this->assertNotContains($cizi->id, FinanceAccess::viditelneRozpocty((int) $nas->id, (int) $ja->id));
    }

    private function prostor(User $vlastnik, string $nazev, bool $vychozi): GallerySpace
    {
        return GallerySpace::create([
            'uuid' => (string) Str::uuid(),
            'name' => $nazev,
            'slug' => Str::slug($nazev).'-'.Str::random(4),
            'owner_id' => $vlastnik->id,
            'is_default' => $vychozi,
        ]);
    }

    private function rozpocet(GallerySpace $prostor, ?int $vlastnik, string $nazev): Budget
    {
        return Budget::create([
            'gallery_space_id' => $prostor->id,
            'owner_user_id' => $vlastnik,
            'name' => $nazev,
            'currency' => 'CZK',
            'starts_on' => now()->startOfMonth()->toDateString(),
            'created_by' => $vlastnik ?? $prostor->owner_id,
        ]);
    }
}
