<?php

namespace Tests\Feature;

use App\Jobs\GenerateExportJob;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Do vývozu se fotka dostane i bez zmenšenin.
 *
 * `GenerateExportJob` bral `large ?? medium` a fotku bez obou **tiše přeskočil**:
 * ZIP se stáhl, tvářil se hotově a pár fotek v něm prostě nebyl. Nikde o tom
 * nebyla zmínka — ani v protokolu, ani v odpovědi.
 *
 * Do teď to bylo jen u fotek, u kterých se zmenšeniny nepovedly. Jakmile ale
 * úklid variant začne zmenšeniny mazat kvůli místu na disku, stal by se z toho
 * pravidelný jev: vyklizená fotka by zmizela z vývozu. Originál tu byl celou
 * dobu vedle, takže stačí sáhnout po něm.
 */
class VyvozBereOriginalTest extends TestCase
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

    public function test_fotka_bez_zmensenin_ve_vyvozu_nechybi(): void
    {
        $sZmensenim = $this->fotka('s-velkou.jpg', ['original', 'large']);
        $jenOriginal = $this->fotka('jen-original.jpg', ['original']);

        $jmena = $this->vyvez([$sZmensenim->id, $jenOriginal->id]);

        $this->assertContains('s-velkou.jpg', $jmena);
        $this->assertContains('jen-original.jpg', $jmena,
            'Fotka bez zmenšenin se do ZIPu nedostala — vývoz by po úklidu variant tiše ubýval.');
    }

    public function test_prednost_ma_dal_zmensenina(): void
    {
        $fotka = $this->fotka('vylet.jpg', ['original', 'large']);

        $this->assertSame(
            Storage::disk('public')->path('media/'.$fotka->uuid.'/large.webp'),
            $this->zdroj($fotka),
            'Originál je mnohonásobně větší; dokud zmenšenina je, má se posílat ona.',
        );
    }

    /**
     * Jména souborů v hotovém ZIPu.
     *
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function vyvez(array $ids): array
    {
        $cesta = storage_path('app/exports/zkouska-vyvozu.zip');
        @unlink($cesta);

        (new GenerateExportJob(
            $this->adri->id,
            ['type' => 'selection', 'media_ids' => $ids],
            'zkouska-vyvozu',
            $this->prostor->id,
        ))->handle();

        $this->assertFileExists($cesta, 'Vývoz ZIP nevyrobil.');

        $zip = new \ZipArchive;
        $zip->open($cesta);
        $jmena = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $jmena[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($cesta);

        return $jmena;
    }

    /** Ze které cesty vývoz fotku vzal. */
    private function zdroj(MediaItem $fotka): string
    {
        $vybrana = $fotka->getVariant('large') ?? $fotka->getVariant('medium') ?? $fotka->getVariant('original');

        return Storage::disk('public')->path($vybrana->path);
    }

    /** @param  list<string>  $typy */
    private function fotka(string $jmeno, array $typy): MediaItem
    {
        $m = MediaItem::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => $jmeno,
            'safe_filename' => $jmeno,
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1000,
        ]);

        foreach ($typy as $typ) {
            $pripona = $typ === 'original' ? 'jpg' : 'webp';
            $cesta = 'media/'.$m->uuid.'/'.$typ.'.'.$pripona;
            Storage::disk('public')->put($cesta, $typ);
            DB::table('media_variants')->insert([
                'media_item_id' => $m->id, 'type' => $typ, 'disk' => 'public', 'path' => $cesta,
                'size_bytes' => 10, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $m;
    }
}
