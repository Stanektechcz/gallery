<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\SharedLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Odkaz „bez data a místa" nevydá polohu ani ve staženém souboru.
 *
 * Dialog sdílení slibuje: „Zobrazit datum a místo — vypněte, když nechcete
 * prozradit, kde jste byli." Vypnutí zapsalo `hide_gps`, jenže to nikdo
 * nečetl: stažení z odkazu vydalo originál i s EXIF — tedy i se souřadnicemi,
 * obvykle domova.
 *
 * Odstranit EXIF z originálu nejde bez újmy: je v něm i otočení snímku, takže
 * by fotka z telefonu přišla převrácená. Zmenšenina `large` (2560 px) vzniká
 * už otočená a bez metadat (`strip: true`) — u takového odkazu se stahuje ona.
 * Video má polohu zapsanou přímo v souboru, a tak se u něj stažení odmítne
 * i s vysvětlením.
 */
class SdileniBezPolohyTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->adri = User::factory()->create();
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);
    }

    public function test_bez_polohy_se_stahne_kopie_bez_metadat(): void
    {
        $fotka = $this->fotka(['original' => 'ORIGINAL-S-GPS', 'large' => 'KOPIE-BEZ-METADAT']);
        $odkaz = $this->odkaz($fotka, bezPolohy: true);

        $odpoved = $this->get("/s/{$odkaz->token}/media/{$fotka->uuid}/download")->assertOk();

        $this->assertSame('KOPIE-BEZ-METADAT', $odpoved->streamedContent(),
            'Odkaz bez polohy vydal originál i s EXIF.');
        $this->assertStringContainsString('vylet.webp', (string) $odpoved->headers->get('content-disposition'));
    }

    public function test_s_polohou_se_stahne_original(): void
    {
        $fotka = $this->fotka(['original' => 'ORIGINAL-S-GPS', 'large' => 'KOPIE-BEZ-METADAT']);
        $odkaz = $this->odkaz($fotka, bezPolohy: false);

        $odpoved = $this->get("/s/{$odkaz->token}/media/{$fotka->uuid}/download")->assertOk();

        $this->assertSame('ORIGINAL-S-GPS', $odpoved->streamedContent());
    }

    public function test_bez_polohy_a_bez_kopie_se_nevyda_nic(): void
    {
        $fotka = $this->fotka(['original' => 'ORIGINAL-S-GPS']);
        $odkaz = $this->odkaz($fotka, bezPolohy: true);

        $this->get("/s/{$odkaz->token}/media/{$fotka->uuid}/download")->assertForbidden();
    }

    public function test_bez_polohy_video_nejde_stahnout(): void
    {
        $video = $this->fotka(['original' => 'VIDEO-S-POLOHOU', 'video_poster' => 'PLAKAT'], 'video');
        $odkaz = $this->odkaz($video, bezPolohy: true);

        $this->get("/s/{$odkaz->token}/media/{$video->uuid}/download")->assertForbidden();
    }

    /** Ani stránka nepoužije originál jako náhradní náhled. */
    public function test_bez_polohy_stranka_nevyda_original(): void
    {
        $fotka = $this->fotka(['original' => 'ORIGINAL-S-GPS']);
        $odkaz = $this->odkaz($fotka, bezPolohy: true);

        // Ze samotné stránky se to nepozná — podepsaná adresa nese příponu
        // v parametru, takže „original.jpg" v ní doslova není. Kontrolují se data.
        $this->get("/s/{$odkaz->token}")
            ->assertOk()
            ->assertInertia(fn (Assert $stranka) => $stranka
                ->component('Shares/Show')
                ->where('media.0.variants', fn ($varianty) => collect($varianty)->where('type', 'original')->isEmpty()));

        // A bez skrytí polohy originál jako náhradní náhled dál je.
        $odkaz->update(['hide_gps' => false, 'show_metadata' => true]);
        $this->get("/s/{$odkaz->token}")
            ->assertInertia(fn (Assert $stranka) => $stranka
                ->where('media.0.variants.0.type', 'original'));
    }

    /**
     * Stránka dostane jen náhledy, které opravdu kreslí.
     *
     * Filtr hlídal jen originál. Kopie videa k přehrávání (`video_compat`)
     * i `large` šly do stránky s podepsanou adresou dál — a kopie videa nesla
     * metadata zdroje včetně polohy. Stránka přitom kreslí jen náhled.
     */
    public function test_bez_polohy_stranka_nevyda_kopii_videa(): void
    {
        $video = $this->fotka([
            'original' => 'VIDEO-S-POLOHOU',
            'video_poster' => 'PLAKAT',
            'video_compat' => 'KOPIE-S-POLOHOU',
        ], 'video');
        $odkaz = $this->odkaz($video, bezPolohy: true);

        $this->get("/s/{$odkaz->token}")
            ->assertOk()
            ->assertInertia(fn (Assert $stranka) => $stranka
                ->component('Shares/Show')
                ->where('media.0.variants', fn ($varianty) => collect($varianty)->pluck('type')->all() === ['video_poster']));
    }

    /** Ani velká kopie fotky na stránku nepatří — stránka kreslí náhled. */
    public function test_stranka_vyda_jen_nahledy(): void
    {
        $fotka = $this->fotka(['original' => 'ORIGINAL-S-GPS', 'thumbnail' => 'NAHLED', 'large' => 'VELKA']);
        $odkaz = $this->odkaz($fotka, bezPolohy: false);

        $this->get("/s/{$odkaz->token}")
            ->assertOk()
            ->assertInertia(fn (Assert $stranka) => $stranka
                ->where('media.0.variants', fn ($varianty) => collect($varianty)->pluck('type')->all() === ['thumbnail']));
    }

    /**
     * Kopie videa se kóduje bez metadat zdroje.
     *
     * Telefon zapisuje polohu do metadat souboru (u iPhonu `ISO6709`) a ffmpeg
     * je do kopie bez `-map_metadata -1` přenáší. Spustit ffmpeg v testu nejde
     * (na stroji nemusí být), takže se kontroluje samotný příkaz — obě větve,
     * hardwarová i softwarová.
     */
    public function test_kopie_videa_se_koduje_bez_metadat(): void
    {
        $zdroj = (string) file_get_contents(app_path('Services/Media/VideoProcessingService.php'));
        preg_match_all("/'%s -y -i %s[^']*-movflags \\+faststart[^']*'/", $zdroj, $prikazy);

        $this->assertCount(2, $prikazy[0], 'Čekal jsem dva příkazy pro kopii videa (hardwarový a náhradní).');
        foreach ($prikazy[0] as $prikaz) {
            $this->assertStringContainsString('-map_metadata -1', $prikaz);
            $this->assertStringContainsString('-map_chapters -1', $prikaz);
        }
    }

    // ——— pomocné ———

    /** @param  array<string, string>  $varianty  typ => obsah souboru */
    private function fotka(array $varianty, string $druh = 'photo'): MediaItem
    {
        $m = MediaItem::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => $druh === 'video' ? 'vylet.mp4' : 'vylet.jpg',
            'safe_filename' => 'vylet.jpg',
            'extension' => $druh === 'video' ? 'mp4' : 'jpg',
            'mime_type' => $druh === 'video' ? 'video/mp4' : 'image/jpeg',
            'media_type' => $druh,
            'size_bytes' => 1000,
        ]);

        foreach ($varianty as $typ => $obsah) {
            $cesta = 'media/'.$m->uuid.'/'.$typ.($typ === 'original' ? '.jpg' : '.webp');
            Storage::disk('public')->put($cesta, $obsah);
            DB::table('media_variants')->insert([
                'media_item_id' => $m->id, 'type' => $typ, 'disk' => 'public', 'path' => $cesta,
                'size_bytes' => strlen($obsah), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $m;
    }

    private function odkaz(MediaItem $m, bool $bezPolohy): SharedLink
    {
        return SharedLink::create([
            'created_by' => $this->adri->id,
            'gallery_space_id' => $this->prostor->id,
            'target_type' => 'media',
            'target_id' => $m->id,
            'allow_download' => true,
            'show_metadata' => ! $bezPolohy,
            'hide_gps' => $bezPolohy,
            'is_active' => true,
        ]);
    }
}
