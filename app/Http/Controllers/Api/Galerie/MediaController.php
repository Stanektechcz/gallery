<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Controller;
use App\Jobs\Media\CalculateMediaHashesJob;
use App\Jobs\Media\ExtractMediaMetadataJob;
use App\Jobs\Media\GenerateImageVariantsJob;
use App\Jobs\Media\GenerateVideoPosterJob;
use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Services\Billing\EntitlementService;
use App\Services\Media\MediaFormatService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Nahrávání médií z prototypu.
 *
 * Malé soubory jedním POSTem, velké po částech — klient (`galerie-api.js`) posílá
 * části s hlavičkami `X-Upload-Id`, `X-Chunk-Index`, `X-Chunk-Count` a nic jiného
 * o serveru vědět nepotřebuje.
 *
 * **Zapisuje se do existující knihovny**, ne do vlastní tabulky, kterou scaffold
 * prototypu navrhoval. Druhý sklad fotek by znamenal dva seznamy téhož: fotka
 * nahraná z prototypu by se neobjevila v časové ose, nezapočítala by se do tarifu
 * a koš by o ní nevěděl. Řádek tedy vzniká stejný, jaký zakládá `UploadController`,
 * včetně varianty `original` a navazujících úloh.
 */
class MediaController extends Controller
{
    use UrcujePar;

    /** Rozpracované části velkého souboru. Úklid má na starost `gallery:clean-temp`. */
    private const CASTI_DISK = 'local';

    private const CASTI_ADRESAR = 'upload_chunks/galerie';

    /** Malý soubor jedním požadavkem. */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:512000'],
            'taken_at' => ['nullable'],
        ]);

        $soubor = $request->file('file');

        return $this->prijmi(
            $request,
            $soubor->getRealPath(),
            $soubor->getClientOriginalName(),
            $request->input('taken_at'),
        );
    }

    /**
     * Jedna část velkého souboru.
     *
     * Části se skládají na disku pod dočasným jménem; poslední část soubor uzavře
     * a založí záznam. Skládá se **proudem**, ne do paměti — u videa na dvě stě
     * megabajtů by PHP jinak spolklo dvě stě megabajtů paměti na jeden požadavek.
     */
    public function chunk(Request $request): JsonResponse
    {
        $id = (string) $request->header('X-Upload-Id');
        $poradi = (int) $request->header('X-Chunk-Index');
        $celkem = (int) $request->header('X-Chunk-Count');
        $jmeno = $this->bezpecneJmeno(urldecode((string) $request->header('X-File-Name', 'soubor')));

        abort_unless($celkem > 0 && $poradi >= 0 && $poradi < $celkem, 422, 'Chybí hlavičky nahrávání.');
        // Identifikátor jde do cesty na disku, takže se nekontroluje jen na prázdno.
        abort_unless(preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1, 422, 'Neplatný identifikátor nahrávání.');

        $disk = Storage::disk(self::CASTI_DISK);
        $adresar = self::CASTI_ADRESAR.'/'.$id;

        $vstup = $request->getContent(true);
        $disk->writeStream($adresar.'/'.str_pad((string) $poradi, 6, '0', STR_PAD_LEFT), $vstup);
        if (is_resource($vstup)) {
            fclose($vstup);
        }

        $casti = $disk->files($adresar);
        sort($casti);

        if (count($casti) < $celkem) {
            return response()->json([
                'id' => $id,
                'received' => count($casti),
                'of' => $celkem,
                'status' => 'partial',
            ], 202);
        }

        $cely = tempnam(sys_get_temp_dir(), 'galerie-');
        $vystup = fopen($cely, 'wb');

        try {
            foreach ($casti as $cast) {
                $zdroj = $disk->readStream($cast);
                stream_copy_to_stream($zdroj, $vystup);
                fclose($zdroj);
            }
            fclose($vystup);
            $disk->deleteDirectory($adresar);

            return $this->prijmi($request, $cely, $jmeno, null);
        } finally {
            @unlink($cely);
        }
    }

    /**
     * Výdej originálu.
     *
     * Přes aplikaci, ne přes veřejnou adresu — jinak by odkaz na fotku platil
     * i pro toho, kdo se do galerie nikdy nedostal.
     */
    public function raw(Request $request, string $uuid): StreamedResponse
    {
        $media = $this->najdi($request, $uuid);
        $originál = $media->variants()->where('type', 'original')->first();

        abort_if($originál === null, 404, 'Originál tohoto souboru na disku není.');

        return Storage::disk($originál->disk)->response($originál->path, $media->original_filename);
    }

    /** Do koše, ne z disku — trvale maže až úklid po třiceti dnech. */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $media = $this->najdi($request, $uuid);

        $media->update([
            'trashed_at' => now(),
            'purge_after' => now()->addDays((int) config('gallery.trash_retention_days', 30)),
        ]);

        return response()->json(['id' => $media->uuid, 'status' => 'trashed']);
    }

    // ——— přijetí souboru ———

    /**
     * Hotový soubor na disku → řádek v knihovně.
     *
     * @param  string  $cesta  Úplný soubor, ne část
     * @param  mixed  $takenAt  Čas poslední změny souboru z prohlížeče (ms)
     */
    private function prijmi(Request $request, string $cesta, string $jmeno, $takenAt): JsonResponse
    {
        $prostorId = $this->parId($request);
        $bajtu = (int) filesize($cesta);
        $hash = hash_file('sha256', $cesta);

        // Duplicitu nezaložíme dvakrát — knihovna má hlídat originály, ne kopie.
        $stavajici = MediaItem::where('gallery_space_id', $prostorId)
            ->where('sha256', $hash)
            ->whereNull('trashed_at')
            ->first();

        if ($stavajici) {
            return response()->json($this->naKlienta($stavajici, 'duplicate'));
        }

        /*
         * Tarif platí i tady.
         *
         * Bez téhle kontroly by stačilo nahrávat přes prototyp a limit úložiště
         * by neplatil vůbec — `UploadController` ho hlídá, tahle cesta by ho
         * obcházela.
         */
        $prostor = GallerySpace::findOrFail($prostorId);
        $tarif = app(EntitlementService::class);

        if (! $tarif->canStore($prostor, $bajtu)) {
            $vyuziti = $tarif->storageUsage($prostor);
            abort(402, sprintf(
                'Tarif má limit %d GB a ten by se tímhle souborem překročil. Uvolněte místo nebo přejděte na vyšší tarif — viz /cenik.',
                (int) round(($vyuziti['limit_mb'] ?? 0) / 1000),
            ));
        }

        $pripona = $this->pripona($jmeno);
        $mime = $this->mime($cesta, $pripona);
        $druh = MediaFormatService::isVideo($pripona) || str_starts_with($mime, 'video/') ? 'video' : 'photo';

        $media = MediaItem::create([
            'gallery_space_id' => $prostorId,
            'owner_user_id' => $request->user()->id,
            'uploaded_by' => $request->user()->id,
            // Délky sloupců hlídáme sami: SQLite ve vývoji delší hodnotu mlčky
            // zkrátí, MySQL v produkci celý zápis odmítne.
            'original_filename' => mb_substr($jmeno, 0, 512),
            'safe_filename' => mb_substr(preg_replace('/[^a-zA-Z0-9._-]/', '_', $jmeno), 0, 512),
            'extension' => $pripona,
            'mime_type' => mb_substr($mime, 0, 100),
            'media_type' => $druh,
            'is_raw' => MediaFormatService::isRaw($pripona),
            'raw_format' => MediaFormatService::isRaw($pripona) ? $pripona : null,
            'size_bytes' => $bajtu,
            'sha256' => $hash,
            'status' => 'ready',
            'storage_status' => 'local_only',
            'uploaded_at' => now(),
            'last_verified_at' => now(),
            /*
             * `taken_at` z prohlížeče je čas poslední změny souboru, ne čas pořízení.
             * Je to ale jediné vodítko, které v tu chvíli existuje, a bez data by
             * fotka spadla na konec časové osy, kam se nikdo nedívá. Skutečné EXIF
             * ho přepíše, jakmile doběhne `ExtractMediaMetadataJob`.
             */
            'taken_at' => $takenAt ? Carbon::createFromTimestampMs((int) $takenAt) : null,
        ]);

        try {
            $ulozeni = 'media/'.$media->uuid.'/original.'.$pripona;
            $zdroj = fopen($cesta, 'rb');
            $ulozeno = Storage::disk('public')->put($ulozeni, $zdroj, 'public');

            if (is_resource($zdroj)) {
                fclose($zdroj);
            }

            abort_unless($ulozeno, 500, 'Soubor se nepodařilo uložit.');

            $media->variants()->create([
                'type' => 'original',
                'disk' => 'public',
                'path' => $ulozeni,
                'mime_type' => $media->mime_type,
                'size_bytes' => $bajtu,
            ]);
        } catch (\Throwable $e) {
            // Poloviční záznam bez souboru je horší než žádný — v knihovně by
            // zůstala dlaždice, kterou nejde otevřít.
            Storage::disk('public')->deleteDirectory('media/'.$media->uuid);
            $media->forceDelete();

            throw $e;
        }

        $this->dopocitej($media);

        AuditLog::record('media.upload', $media, ['filename' => $media->original_filename]);

        return response()->json($this->naKlienta($media, 'stored'), 201);
    }

    /**
     * Náhledy, metadata a otisky.
     *
     * Do fronty, ne do požadavku: nahrávání má skončit hned, jakmile je originál
     * v bezpečí. Nedostupná fronta proto nikdy nesmí shodit už povedené nahrání —
     * fotka je uložená, chybí jí jen náhled.
     */
    private function dopocitej(MediaItem $media): void
    {
        $ulohy = $media->media_type === 'video'
            ? [GenerateVideoPosterJob::class, ExtractMediaMetadataJob::class, CalculateMediaHashesJob::class]
            : [GenerateImageVariantsJob::class, ExtractMediaMetadataJob::class, CalculateMediaHashesJob::class];

        foreach ($ulohy as $uloha) {
            try {
                $uloha::dispatch($media->id)->onQueue('media');
            } catch (\Throwable $e) {
                Log::warning('Doplňkové zpracování média se nepodařilo zařadit', [
                    'media_id' => $media->id,
                    'uloha' => $uloha,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ——— pomocné ———

    private function najdi(Request $request, string $uuid): MediaItem
    {
        $media = MediaItem::where('uuid', $uuid)->first();

        abort_if($media === null, 404, 'Takový soubor tu není.');
        // Globální rozsah už dotaz omezuje na prostory přihlášeného člověka;
        // tohle je druhá vrstva a drží modul u jednoho páru, jak počítá zbytek.
        abort_unless($media->gallery_space_id === $this->parId($request), 403);

        return $media;
    }

    private function naKlienta(MediaItem $media, string $stav): array
    {
        return [
            'id' => $media->uuid,
            'name' => $media->original_filename,
            'bytes' => (int) $media->size_bytes,
            'status' => $stav,
            'type' => $media->media_type,
            'taken_at' => $media->taken_at?->toIso8601String(),
            'url' => route('galerie.media.raw', $media->uuid),
        ];
    }

    /** Jméno od uživatele patří do metadat, nikdy ale nesmí určovat cestu na disku. */
    private function bezpecneJmeno(string $jmeno): string
    {
        $jmeno = basename(str_replace('\\', '/', $jmeno));
        $jmeno = preg_replace('/[\x00-\x1F]/', '', $jmeno);

        return trim($jmeno) === '' ? 'soubor' : $jmeno;
    }

    private function pripona(string $jmeno): string
    {
        $pripona = preg_replace('/[^a-zA-Z0-9]/', '', strtolower(pathinfo($jmeno, PATHINFO_EXTENSION)));

        return mb_substr($pripona ?: 'bin', 0, 20);
    }

    private function mime(string $cesta, string $pripona): string
    {
        if (MediaFormatService::isRaw($pripona)) {
            return MediaFormatService::rawMime($pripona);
        }

        return File::mimeType($cesta) ?: 'application/octet-stream';
    }
}
