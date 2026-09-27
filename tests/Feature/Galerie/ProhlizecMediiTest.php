<?php

namespace Tests\Feature\Galerie;

use App\Http\Middleware\PresmerujStareRozhrani;
use App\Jobs\Media\GenerateImageVariantsJob;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Planovani\DvojiceSHostem;
use Tests\TestCase;

/**
 * Prohlížeč fotky ukáže fotku a přehraje video — ne černý čtverec.
 *
 * Hlášení z produkce 27. 9. 2026: „Fotka ani video se při otevření jejich
 * detailu nenačítají, je tam jen černý čtverec." Mřížka náhledy měla;
 * prohlížeč (počítač i telefon) bere jiné adresy — `full` (velký náhled,
 * `velikost=velky`), `video` / `play` a `poster`. Tady se ty adresy berou
 * přesně z knihovny, jak je dostane prohlížeč, a stahují bez tokenu —
 * `<img>` ani `<video>` hlavičku `Authorization` nepřidají.
 */
class ProhlizecMediiTest extends TestCase
{
    use DvojiceSHostem, RefreshDatabase;

    /** Nejmenší platné WebP (1 × 1 px) — finfo ho pozná jako `image/webp`. */
    private const WEBP = 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA';

    private User $vlastnik;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        [$this->vlastnik, , , $this->prostor] = $this->dvojiceSHostem();
        Sanctum::actingAs($this->vlastnik);
    }

    /** Velký náhled z knihovny se vydá jako obrázek, ne jako příloha ani přesměrování. */
    public function test_velky_nahled_z_knihovny_se_vyda_jako_obrazek(): void
    {
        $fotka = $this->fotka($this->prostor, $this->vlastnik, 'IMG_1.jpg');
        $this->varianta($fotka, 'thumbnail', base64_decode(self::WEBP), 'webp');
        $this->varianta($fotka, 'large', base64_decode(self::WEBP).'velky', 'webp');

        $adresa = $this->adresaZPozadi($this->dlazdice($fotka)['full']);
        $this->assertStringContainsString('velikost=velky', $adresa);

        $odpoved = $this->get($adresa)->assertOk();

        $this->assertSame('image/webp', $odpoved->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $odpoved->headers->get('Content-Disposition'));
        $this->assertStringEndsWith('velky', $odpoved->streamedContent(), 'Prohlížeč má dostat velký náhled, ne 320px z mřížky.');
    }

    /**
     * Záznam varianty bez souboru na disku prohlížeč nezablokuje.
     *
     * Mřížka bere `thumbnail`, prohlížeč `large`. Když `large` v databázi je,
     * ale soubor chybí (starší rozložení adresářů, nedokončený zápis, soubor
     * nepřístupný účtu webu), skončila adresa chybou a v prohlížeči zůstal
     * jen podklad `#111` — černý čtverec. Má se vzít další varianta, která
     * na disku opravdu je.
     */
    public function test_chybejici_soubor_velke_varianty_spadne_na_dalsi(): void
    {
        $fotka = $this->fotka($this->prostor, $this->vlastnik, 'IMG_2.jpg');
        $this->varianta($fotka, 'thumbnail', base64_decode(self::WEBP).'maly', 'webp');
        $this->varianta($fotka, 'medium', base64_decode(self::WEBP).'stredni', 'webp');
        $this->varianta($fotka, 'large', null, 'webp');

        $odpoved = $this->get($this->adresaZPozadi($this->dlazdice($fotka)['full']))->assertOk();

        $this->assertSame('image/webp', $odpoved->headers->get('Content-Type'));
        $this->assertStringEndsWith('stredni', $odpoved->streamedContent());
    }

    /** Totéž u mřížky: chybějící `thumbnail` nahradí jiná varianta. */
    public function test_chybejici_soubor_nahledu_spadne_na_dalsi(): void
    {
        $fotka = $this->fotka($this->prostor, $this->vlastnik, 'IMG_3.jpg');
        $this->varianta($fotka, 'thumbnail', null, 'webp');
        $this->varianta($fotka, 'small', base64_decode(self::WEBP).'maly', 'webp');

        $odpoved = $this->get($this->adresaZPozadi($this->dlazdice($fotka)['bg']))->assertOk();

        $this->assertStringEndsWith('maly', $odpoved->streamedContent());
    }

    /**
     * Originál, který prohlížeč nevykreslí, nepřebije menší náhled.
     *
     * Fotka z iPhonu (HEIC) jen s `thumbnail` — `gallery:thumbnails` dřív
     * doplňoval jen ten. `velikost=velky` pak vydal HEIC originál a Chrome
     * ukázal černou plochu. Má přijít náhled, který se vykreslí, a velká
     * zmenšenina se má zadat k doplnění.
     */
    public function test_heic_original_neprebije_nahled_a_velky_se_doplni(): void
    {
        Queue::fake();
        $fotka = $this->fotka($this->prostor, $this->vlastnik, 'IMG_8.HEIC', ['mime_type' => 'image/heic', 'extension' => 'heic']);
        $this->varianta($fotka, 'original', 'heic-data', 'heic');
        $this->varianta($fotka, 'thumbnail', base64_decode(self::WEBP).'maly', 'webp');

        $odpoved = $this->get($this->adresaZPozadi($this->dlazdice($fotka)['full']))->assertOk();

        $this->assertSame('image/webp', $odpoved->headers->get('Content-Type'));
        $this->assertStringEndsWith('maly', $odpoved->streamedContent());
        Queue::assertPushed(GenerateImageVariantsJob::class, 1);

        // Druhé otevření úlohu nezakládá znovu.
        $this->get($this->adresaZPozadi($this->dlazdice($fotka)['full']))->assertOk();
        Queue::assertPushed(GenerateImageVariantsJob::class, 1);
    }

    /** Fotka s velkou zmenšeninou nic doplňovat nemusí. */
    public function test_s_velkou_zmensninou_se_nic_nedoplnuje(): void
    {
        Queue::fake();
        $fotka = $this->fotka($this->prostor, $this->vlastnik, 'IMG_9.jpg');
        $this->varianta($fotka, 'thumbnail', base64_decode(self::WEBP), 'webp');
        $this->varianta($fotka, 'large', base64_decode(self::WEBP), 'webp');

        $this->get($this->adresaZPozadi($this->dlazdice($fotka)['full']))->assertOk();

        Queue::assertNotPushed(GenerateImageVariantsJob::class);
    }

    /**
     * Zástupný plakát videa, který kreslí server, se vykreslí.
     *
     * Bez FFmpegu (na serveru do 26. 9.) dostalo každé video plakát
     * a náhled `video_placeholder.svg`. SVG šlo jako příloha
     * `application/octet-stream` — plakát i dlaždice zůstaly prázdné.
     */
    public function test_zastupny_plakat_videa_je_obrazek(): void
    {
        $video = $this->fotka($this->prostor, $this->vlastnik, 'IMG_10.mp4', [
            'media_type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'duration_ms' => 4000,
        ]);
        $this->varianta($video, 'original', $this->mp4(), 'mp4');
        $cesta = 'media/'.$video->uuid.'/video_placeholder.svg';
        Storage::disk('public')->put($cesta, '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="4"></svg>');
        foreach (['video_poster', 'thumbnail'] as $typ) {
            DB::table('media_variants')->insert([
                'media_item_id' => $video->id, 'type' => $typ, 'disk' => 'public', 'path' => $cesta, 'format' => 'svg',
                'mime_type' => 'image/svg+xml', 'size_bytes' => 10, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $odpoved = $this->get($this->dlazdice($video)['poster'])->assertOk();

        $this->assertSame('image/svg+xml', $odpoved->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $odpoved->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', (string) $odpoved->headers->get('Content-Security-Policy'));
    }

    /** Jiné SVG (třeba nahrané) zůstává přílohou — skript v něm se nesmí otevřít jako stránka. */
    public function test_cizi_svg_zustava_prilohou(): void
    {
        $fotka = $this->fotka($this->prostor, $this->vlastnik, 'kresba.svg', ['mime_type' => 'image/svg+xml', 'extension' => 'svg']);
        $cesta = 'media/'.$fotka->uuid.'/thumbnail.svg';
        Storage::disk('public')->put($cesta, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        DB::table('media_variants')->insert([
            'media_item_id' => $fotka->id, 'type' => 'thumbnail', 'disk' => 'public', 'path' => $cesta, 'format' => 'svg',
            'size_bytes' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $odpoved = $this->get($this->adresaZPozadi($this->dlazdice($fotka)['bg']))->assertOk();

        $this->assertSame('application/octet-stream', $odpoved->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', (string) $odpoved->headers->get('Content-Disposition'));
    }

    /** Video bez souboru na disku nedostane adresu — prohlížeč řekne proč, místo černého přehrávače. */
    public function test_video_bez_souboru_na_disku_nema_adresu(): void
    {
        $video = $this->fotka($this->prostor, $this->vlastnik, 'IMG_11.mp4', [
            'media_type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'duration_ms' => 4000,
        ]);
        $this->varianta($video, 'original', null, 'mp4');

        $this->assertNull($this->dlazdice($video)['video']);
    }

    /**
     * Video z knihovny se přehraje po částech a se svým skutečným typem.
     *
     * Převod (`video_compat`) je MP4, ale typ se bral z originálu — u videa
     * z iPhonu `video/quicktime`. S `nosniff` na odpovědi pak Safari a část
     * prohlížečů soubor odmítly a přehrávač zůstal černý.
     */
    public function test_video_z_knihovny_se_prehraje_jako_mp4(): void
    {
        $video = $this->fotka($this->prostor, $this->vlastnik, 'IMG_4.MOV', [
            'media_type' => 'video', 'mime_type' => 'video/quicktime', 'extension' => 'mov', 'duration_ms' => 4000,
        ]);
        $this->varianta($video, 'original', $this->mov(), 'mov');
        $this->varianta($video, 'video_compat', $this->mp4(), 'mp4');
        $this->varianta($video, 'video_poster', base64_decode(self::WEBP), 'webp');

        $dlazdice = $this->dlazdice($video);
        $this->assertNotNull($dlazdice['video']);
        $this->assertNotNull($dlazdice['poster']);

        $odpoved = $this->withHeader('Range', 'bytes=0-')->get($dlazdice['video']);

        $this->assertSame(206, $odpoved->getStatusCode());
        $this->assertSame('video/mp4', $odpoved->headers->get('Content-Type'));
        $this->assertSame('bytes', $odpoved->headers->get('Accept-Ranges'));

        $plakat = $this->withHeaders(['Range' => ''])->get($dlazdice['poster'])->assertOk();
        $this->assertSame('image/webp', $plakat->headers->get('Content-Type'));
    }

    /** Chybí-li soubor převodu, přehraje se originál — ne chyba. */
    public function test_chybejici_prevod_videa_spadne_na_original(): void
    {
        $video = $this->fotka($this->prostor, $this->vlastnik, 'IMG_5.mp4', [
            'media_type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'duration_ms' => 4000,
        ]);
        $this->varianta($video, 'original', $this->mp4().'original', 'mp4');
        $this->varianta($video, 'video_compat', null, 'mp4');

        $odpoved = $this->get($this->dlazdice($video)['video'])->assertOk();

        $this->assertSame('video/mp4', $odpoved->headers->get('Content-Type'));
    }

    /** Telefon dostává tytéž adresy (`full`, `play`, `poster`) a ty fungují. */
    public function test_telefon_dostane_funkcni_adresy(): void
    {
        $fotka = $this->fotka($this->prostor, $this->vlastnik, 'IMG_6.jpg');
        $this->varianta($fotka, 'thumbnail', base64_decode(self::WEBP), 'webp');
        $this->varianta($fotka, 'large', base64_decode(self::WEBP), 'webp');
        $video = $this->fotka($this->prostor, $this->vlastnik, 'IMG_7.mp4', [
            'media_type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'duration_ms' => 4000,
        ]);
        $this->varianta($video, 'original', $this->mp4(), 'mp4');
        $this->varianta($video, 'video_poster', base64_decode(self::WEBP), 'webp');

        $mobil = collect($this->getJson('/api/data/knihovna')->assertOk()->json('data.MOBIL.PHOTOS'));
        $f = $mobil->firstWhere('id', $fotka->uuid);
        $v = $mobil->firstWhere('id', $video->uuid);

        $this->get($this->adresaZPozadi($f['full']))->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get($v['play'])->assertOk()->assertHeader('Content-Type', 'video/mp4');
        $this->get($v['poster'])->assertOk()->assertHeader('Content-Type', 'image/webp');
    }

    /**
     * Adresy médií nepřesměrovávají na stránku aplikace.
     *
     * `<img>` ani `<video>` přesměrování na HTML nevykreslí. Přesměrování
     * starého rozhraní jde podle jména cesty — cesty médií v seznamu být
     * nesmí.
     */
    public function test_cesty_medii_nejsou_v_presmerovani_stareho_rozhrani(): void
    {
        foreach (['galerie.media.thumb', 'galerie.media.video', 'galerie.media.raw', 'galerie.chat.nahled',
            'media.full', 'media.stream', 'media.download', 'media.file'] as $jmeno) {
            $this->assertArrayNotHasKey($jmeno, PresmerujStareRozhrani::PRESMEROVANI, $jmeno);
        }
    }

    // ——— pomůcky ———

    /** @return array<string, mixed> */
    private function dlazdice(MediaItem $m): array
    {
        $radek = collect($this->getJson('/api/data/knihovna')->assertOk()->json('data.PHOTOS'))->firstWhere('id', $m->uuid);
        $this->assertNotNull($radek, 'Položka v knihovně chybí.');

        return $radek + ['video' => null, 'poster' => null];
    }

    /** `url('…') center/contain …` → adresa, jak ji prohlížeč stáhne. */
    private function adresaZPozadi(?string $pozadi): string
    {
        $this->assertNotNull($pozadi);
        $this->assertSame(1, preg_match("/url\\('([^']+)'\\)/", $pozadi, $m), 'Pozadí nemá adresu: '.$pozadi);

        return html_entity_decode($m[1]);
    }

    /** `null` obsah = záznam v databázi bez souboru na disku. */
    private function varianta(MediaItem $m, string $typ, ?string $obsah, string $pripona): void
    {
        $cesta = 'media/'.$m->uuid.'/'.$typ.'.'.$pripona;
        if ($obsah !== null) {
            Storage::disk('public')->put($cesta, $obsah);
        }

        DB::table('media_variants')->insert([
            'media_item_id' => $m->id, 'type' => $typ, 'disk' => 'public', 'path' => $cesta,
            'size_bytes' => strlen((string) $obsah), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Hlavička MP4 (`ftyp isom`) — finfo z ní pozná `video/mp4`. */
    private function mp4(): string
    {
        return "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 64);
    }

    /** Hlavička QuickTime (`ftyp qt`). */
    private function mov(): string
    {
        return "\x00\x00\x00\x14ftypqt  \x00\x00\x00\x00qt  ".str_repeat("\x00", 64);
    }
}
