<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\Tag;
use App\Models\User;
use App\Support\SpaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Oblíbené a úpravy fotek, které přišly jako změna stavu.
 *
 * Srdíčko, popisek, místo, datum pořízení a štítky se v prototypu zapisovaly
 * jen do `favs` a `edits` ve společném stavu. Na obrazovce to vypadalo
 * uložené, jenže knihovna, hledání, mapa, druhé rozhraní i složky na Disku
 * pracují s databází — a v ní se nezměnilo nic. Opravené datum tak fotku na
 * časové ose nepřesunulo nikam jinam než v jednom prohlížeči.
 *
 * Klíče **zůstávají ve stavu**: prototyp z nich kreslí okamžitě, bez čekání na
 * nové načtení knihovny. Do databáze se propíše jen to, co se oproti minulému
 * stavu opravdu změnilo — celý seznam úprav chodí s každým zápisem znovu.
 */
class MediaVeStavu
{
    public function tykaSe(array $patch): bool
    {
        return array_key_exists('favs', $patch) || array_key_exists('edits', $patch);
    }

    /**
     * @param  array<string, mixed>  $patch
     * @param  array<string, mixed>  $predtim  stav před zápisem (asociativně)
     * @return list<string> klíče, jejichž zápis se nepovedl (dluh k zopakování)
     */
    public function zpracuj(array $patch, array $predtim, GallerySpace $prostor, User $kdo): array
    {
        $dluh = [];

        /*
         * Každý klíč zvlášť a chyba se hlásí zpátky.
         *
         * Dřív to byl jeden `try` kolem obojího s `catch`, který jen zapsal do
         * logu: chyba u srdíček vzala i úpravy a hlavně se obojí ztratilo
         * natrvalo. Rozdíl se totiž počítá proti stavu **před** uložením a stav
         * se uložil tak jako tak — při dalším požadavku už nebylo co zapsat,
         * jenom obrazovka dál ukazovala popisek, který v knihovně nebyl.
         * Klíč se proto vrátí jako dluh a `StateController` ho zopakuje.
         */
        if (array_key_exists('favs', $patch)) {
            try {
                $this->oblibene($this->mapa($patch['favs']), $prostor, $kdo);
            } catch (\Throwable $e) {
                $dluh[] = 'favs';
                Log::warning('Oblíbené ze stavu se nepodařilo propsat', ['chyba' => $e->getMessage()]);
            }
        }

        if (is_array($patch['edits'] ?? null)) {
            try {
                $this->upravy($patch['edits'], is_array($predtim['edits'] ?? null) ? $predtim['edits'] : [], $prostor, $kdo);
            } catch (\Throwable $e) {
                $dluh[] = 'edits';
                Log::warning('Úpravy fotek ze stavu se nepodařilo propsat', ['chyba' => $e->getMessage()]);
            }
        }

        return $dluh;
    }

    /**
     * `{uuid: true|false}`; starší klient posílal seznam identifikátorů.
     *
     * @return array<string, bool>
     */
    private function mapa(mixed $hodnota): array
    {
        if (! is_array($hodnota)) {
            return [];
        }

        if (array_is_list($hodnota) && $hodnota !== [] && is_string($hodnota[0])) {
            return array_fill_keys($hodnota, true);
        }

        $mapa = [];

        foreach ($hodnota as $klic => $zapnuto) {
            if (is_string($klic) && Str::isUuid($klic)) {
                $mapa[$klic] = (bool) $zapnuto;
            }
        }

        return $mapa;
    }

    /**
     * Srdíčka podle toho, co je v databázi — ne podle minulého stavu.
     *
     * Dřív se rozdíl počítal proti `favs` ve sdíleném stavu dvojice. Jenže
     * srdíčka jsou každého vlastní, takže se ten klíč do společného dokumentu
     * neukládá (`CoupleState::NEUKLADAT`) — a proti prázdnému „předtím" vypadá
     * odebrání srdíčka jako žádná změna. Pravda je v `user_favorites`: co tam
     * pro tohohle člověka leží a co přišlo z prohlížeče.
     *
     * Jako vedlejší efekt zmizel celý problém staré kopie: nezáleží na tom,
     * co měl prohlížeč naposledy, jen na tom, co má teď.
     *
     * @param  array<string, bool>  $ted
     */
    private function oblibene(array $ted, GallerySpace $prostor, User $kdo): void
    {
        if ($ted === []) {
            return;
        }

        $id = $this->media($prostor, array_keys($ted))->pluck('id', 'uuid');

        if ($id->isEmpty()) {
            return;
        }

        $uz = DB::table('user_favorites')
            ->where('user_id', $kdo->id)
            ->whereIn('media_item_id', $id->values()->all())
            ->pluck('media_item_id')
            ->flip();

        foreach ($ted as $uuid => $zapnuto) {
            if (! isset($id[$uuid])) {
                continue;
            }

            if ($zapnuto === $uz->has($id[$uuid])) {
                continue;
            }

            if ($zapnuto) {
                DB::table('user_favorites')->insertOrIgnore(['user_id' => $kdo->id, 'media_item_id' => $id[$uuid], 'created_at' => now()]);
            } else {
                DB::table('user_favorites')->where('user_id', $kdo->id)->where('media_item_id', $id[$uuid])->delete();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $ted
     * @param  array<string, mixed>  $predtim
     */
    private function upravy(array $ted, array $predtim, GallerySpace $prostor, User $kdo): void
    {
        $zmenene = array_filter($ted, fn ($uprava, $uuid) => is_string($uuid) && Str::isUuid($uuid) && is_array($uprava)
            && json_encode($uprava) !== json_encode($predtim[$uuid] ?? null), ARRAY_FILTER_USE_BOTH);

        if ($zmenene === []) {
            return;
        }

        $media = $this->media($prostor, array_keys($zmenene))->get()->keyBy('uuid');

        foreach ($zmenene as $uuid => $uprava) {
            $m = $media[$uuid] ?? null;

            if ($m === null) {
                continue;
            }

            $stare = is_array($predtim[$uuid] ?? null) ? $predtim[$uuid] : [];
            $zmena = fn (string $pole) => array_key_exists($pole, $uprava) && ($stare[$pole] ?? null) !== $uprava[$pole];
            $sloupce = [];

            if ($zmena('caption')) {
                $sloupce['caption'] = mb_substr(trim((string) $uprava['caption']), 0, 2000) ?: null;
            }

            if ($zmena('place')) {
                $sloupce['location_name'] = mb_substr(trim((string) $uprava['place']), 0, 255) ?: null;
            }

            if ($zmena('dateVal') || $zmena('timeVal')) {
                $kdy = $this->datum($uprava['dateVal'] ?? null, $uprava['timeVal'] ?? null, $m->taken_at);

                if ($kdy !== null) {
                    $sloupce['taken_at'] = $kdy;
                }
            }

            if ($sloupce !== []) {
                $m->forceFill($sloupce)->save();
            }

            if ($zmena('tags') && is_array($uprava['tags'])) {
                $this->stitky($m, $uprava['tags'], $prostor, $kdo);
            }

            if ($zmena('people') && is_array($uprava['people'])) {
                $this->osoby($m, $uprava['people'], $prostor, $kdo);
            }
        }
    }

    /** Datum a čas z formuláře; co chybí, zůstává z původního data pořízení. */
    private function datum(mixed $datum, mixed $cas, mixed $puvodni): ?CarbonImmutable
    {
        $zaklad = $puvodni ? CarbonImmutable::parse($puvodni) : CarbonImmutable::now()->setTime(12, 0);

        if (is_string($datum) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $datum, $d) && checkdate((int) $d[2], (int) $d[3], (int) $d[1])) {
            $zaklad = $zaklad->setDate((int) $d[1], (int) $d[2], (int) $d[3]);
        } elseif ($datum !== null && $datum !== '') {
            return null;
        }

        if (is_string($cas) && preg_match('/^(\d{1,2}):(\d{2})$/', $cas, $c) && (int) $c[1] < 24 && (int) $c[2] < 60) {
            $zaklad = $zaklad->setTime((int) $c[1], (int) $c[2]);
        }

        return $zaklad;
    }

    /** @param  list<mixed>  $nazvy */
    private function stitky(MediaItem $m, array $nazvy, GallerySpace $prostor, User $kdo): void
    {
        $id = [];

        foreach (array_slice($nazvy, 0, 50) as $nazev) {
            $nazev = trim((string) $nazev, " \t#");
            $slug = Str::slug($nazev);

            if ($nazev === '' || $slug === '') {
                continue;
            }

            $stitek = Tag::firstOrCreate(
                ['gallery_space_id' => $prostor->id, 'slug' => mb_substr($slug, 0, 120)],
                ['name' => mb_substr($nazev, 0, 120), 'depth' => 0, 'materialized_path' => '', 'created_by' => $kdo->id],
            );

            $id[$stitek->id] = ['tagged_by' => $kdo->id, 'created_at' => now()];
        }

        $m->tags()->sync($id);
    }

    /**
     * Kdo je na fotce — podle jména.
     *
     * Označit osobu šlo jen ve starém rozhraní; galerie Lidi jen ukazovala
     * a dvojice je neměla jak naplnit. Známé jméno (bez ohledu na velikost
     * písmen) se použije, nové založí osobu. Skrytá osoba na fotce zůstává,
     * i když ji obrazovka neukazuje — odebrat ji tak omylem nejde.
     *
     * @param  list<mixed>  $jmena
     */
    private function osoby(MediaItem $m, array $jmena, GallerySpace $prostor, User $kdo): void
    {
        $id = [];

        foreach (array_slice($jmena, 0, 30) as $jmeno) {
            $jmeno = mb_substr(trim((string) $jmeno), 0, 100);

            if ($jmeno === '') {
                continue;
            }

            $osoba = Person::withoutGlobalScope(SpaceContext::SCOPE)
                ->where('gallery_space_id', $prostor->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($jmeno)])
                ->first()
                ?? Person::withoutGlobalScope(SpaceContext::SCOPE)->create([
                    'gallery_space_id' => $prostor->id,
                    'name' => $jmeno,
                    'created_by' => $kdo->id,
                ]);

            $id[$osoba->id] = ['tagged_by' => $kdo->id, 'created_at' => now()];
        }

        $skryte = $m->people()->where('people.is_hidden', true)->pluck('people.id')->all();

        foreach ($skryte as $osoba) {
            $id[$osoba] ??= ['tagged_by' => $kdo->id, 'created_at' => now()];
        }

        $m->people()->sync($id);
    }

    /** @param  list<string>  $uuid */
    private function media(GallerySpace $prostor, array $uuid)
    {
        return MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereIn('uuid', $uuid);
    }
}
