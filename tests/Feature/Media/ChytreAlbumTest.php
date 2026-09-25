<?php

namespace Tests\Feature\Media;

use App\Models\Album;
use App\Services\Media\SmartAlbumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pravidlo chytrého alba s hodnotou v nečekaném tvaru nesmí shodit stránku.
 *
 * Validace pouští `value` jako cokoli (`nullable`). Pole tam, kde služba
 * čeká text (`camera_make`, `date_from`…), skončilo „Array to string
 * conversion" — a protože se pravidlo uložilo, padala stránka alba i při
 * každém dalším otevření.
 */
class ChytreAlbumTest extends TestCase
{
    use RefreshDatabase;
    use VytvariMedia;

    private function album(array $pravidla): Album
    {
        return Album::create([
            'gallery_space_id' => $this->prostor->id,
            'title' => 'Chytré',
            'slug' => 'chytre-'.uniqid(),
            'visibility' => 'shared',
            'created_by' => $this->adri->id,
            'updated_by' => $this->adri->id,
            // Album `smart_rules` nepřetypovává; kontroler ukládá JSON.
            'smart_rules' => json_encode($pravidla),
        ]);
    }

    public function test_pole_misto_textu_neshodi_dotaz_ani_popisek(): void
    {
        $this->zalozProstor();
        $fotka = $this->media(['camera_make' => 'Apple', 'is_favorite' => true]);
        $podminky = [
            ['field' => 'camera_make', 'op' => 'eq', 'value' => ['Apple', ['vnořené']]],
            ['field' => 'date_from', 'op' => 'gte', 'value' => ['2026-01-01']],
            ['field' => 'taken_year', 'op' => 'eq', 'value' => [2026]],
            ['field' => 'media_type', 'op' => 'eq', 'value' => ['photo']],
            ['field' => 'min_width', 'op' => 'gte', 'value' => ['x' => 1]],
            ['field' => 'rating', 'op' => ['gte'], 'value' => [4]],
            ['field' => 'tag_id', 'op' => 'in', 'value' => [[1, 2]]],
            'není-podmínka',
            ['field' => 'is_favorite', 'op' => 'eq', 'value' => true],
        ];
        $album = $this->album(['match' => 'all', 'conditions' => $podminky]);
        $sluzba = app(SmartAlbumService::class);

        // Neplatné podmínky se přeskočí (jako neznámé pole), platná zůstane.
        $this->assertSame([$fotka->id], $sluzba->buildQuery($album, $this->prostor->id)->pluck('id')->all());
        $this->assertSame(1, $sluzba->count($album, $this->prostor->id));

        foreach ($podminky as $podminka) {
            if (is_array($podminka)) {
                $this->assertIsString(SmartAlbumService::conditionLabel($podminka));
            }
        }
    }

    public function test_pravidla_v_nesmyslnem_tvaru_daji_prazdne_album(): void
    {
        $this->zalozProstor();
        $this->media();

        $this->assertSame(0, app(SmartAlbumService::class)->count($this->album(['match' => 'all', 'conditions' => 'rating>3']), $this->prostor->id));
    }
}
