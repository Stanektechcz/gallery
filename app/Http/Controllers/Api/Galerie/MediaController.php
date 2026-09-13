<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Controller;
use App\Jobs\Media\CalculateMediaHashesJob;
use App\Jobs\Media\ExtractMediaMetadataJob;
use App\Jobs\Media\GenerateImageVariantsJob;
use App\Jobs\Media\GenerateVideoPosterJob;
use App\Jobs\MirrorMediaToCloud;
use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Services\Billing\EntitlementService;
use App\Services\Media\ArchivMedii;
use App\Services\Media\MediaFormatService;
use App\Services\Media\UpravaFotky;
use App\Support\SpaceContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
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

    /** 32 GB po osmi megabajtech je čtyři tisíce částí; víc je chyba nebo útok. */
    private const NEJVIC_CASTI = 8192;

    /** Klient posílá po osmi megabajtech; šestnáct je rezerva, ne pozvánka. */
    private const NEJVETSI_CAST = 16 * 1024 * 1024;

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

        abort_unless($celkem > 0 && $celkem <= self::NEJVIC_CASTI && $poradi >= 0 && $poradi < $celkem, 422, 'Chybí hlavičky nahrávání.');
        // Identifikátor jde do cesty na disku, takže se nekontroluje jen na prázdno.
        abort_unless(preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1, 422, 'Neplatný identifikátor nahrávání.');

        $disk = Storage::disk(self::CASTI_DISK);
        // Složka patří přihlášenému: cizí nahrávání se stejným identifikátorem
        // si nemůže podstrčit ani přepsat části.
        $adresar = self::CASTI_ADRESAR.'/'.$request->user()->id.'-'.$id;

        $vstup = $request->getContent(true);
        $cestaCasti = $adresar.'/'.str_pad((string) $poradi, 6, '0', STR_PAD_LEFT);
        $disk->writeStream($cestaCasti, $vstup);
        if (is_resource($vstup)) {
            fclose($vstup);
        }

        if ((int) $disk->size($cestaCasti) > self::NEJVETSI_CAST) {
            $disk->deleteDirectory($adresar);
            abort(413, 'Část nahrávaného souboru je příliš velká.');
        }

        $casti = $disk->files($adresar);
        sort($casti);

        /*
         * Strop na velikost už během nahrávání.
         *
         * Tarif se kontroloval až po složení celého souboru — do té doby šlo
         * posílat části bez konce a zaplnit disk serveru dřív, než by kontrola
         * vůbec proběhla. Sčítá se po pětadvaceti částech a na konci; sčítat
         * u každé by u čtyř tisíc částí znamenalo miliony dotazů na disk.
         */
        if (count($casti) % 25 === 0 || count($casti) >= $celkem) {
            $zatim = array_sum(array_map(fn (string $c) => (int) $disk->size($c), $casti));

            if ($zatim > (int) config('gallery.max_upload_size_gb', 32) * 1024 * 1024 * 1024) {
                $disk->deleteDirectory($adresar);
                abort(413, 'Soubor je větší, než kolik galerie přijme najednou.');
            }
        }

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

        /*
         * Fotka v trezoru jen s odemčeným trezorem.
         *
         * Stačilo znát uuid a originál ze zamčeného trezoru odešel komukoli
         * z dvojice — i v prohlížeči, kde se trezor nikdy neodemkl. `/files`
         * i archivy to hlídaly, tahle cesta ne. Tváří se jako neexistující,
         * aby nešlo zkoušet, které uuid v trezoru leží.
         */
        abort_if($media->is_hidden && ! $this->trezorOdemceny($request), 404, 'Takový soubor tu není.');

        $originál = $media->variants()->where('type', 'original')->first();

        abort_if($originál === null, 404, 'Originál tohoto souboru na disku není.');

        return $this->doprohlizece($originál->disk, $originál->path, $media->original_filename);
    }

    /**
     * Soubor z knihovny tak, aby v prohlížeči nemohl spustit skript.
     *
     * Typ se bral z obsahu souboru a posílal se `inline`. Soubor, který se
     * vydával za RAW z fotoaparátu (u RAW stačí přípona), ale uvnitř byl HTML,
     * by se na adrese galerie otevřel jako stránka — se skriptem, který dosáhne
     * na token v `localStorage`. `sandbox` v CSP spuštění zakáže i tehdy, když
     * typ projde, a co není obrázek ani video, jde jako příloha.
     *
     * @param  array<string, string>  $dalsi
     */
    private function doprohlizece(string $disk, string $cesta, string $jmeno, array $dalsi = []): StreamedResponse
    {
        $typ = (string) (rescue(fn () => Storage::disk($disk)->mimeType($cesta), null, false) ?: 'application/octet-stream');
        $zobrazit = (str_starts_with($typ, 'image/') && ! str_contains($typ, 'svg'))
            || str_starts_with($typ, 'video/') || str_starts_with($typ, 'audio/');

        return Storage::disk($disk)->response($cesta, $jmeno, $dalsi + [
            'Content-Type' => $zobrazit ? $typ : 'application/octet-stream',
        ] + self::BEZ_SKRIPTU, $zobrazit ? 'inline' : 'attachment');
    }

    /** Hlavičky pro každý soubor z knihovny — viz `doprohlizece()`. */
    private const BEZ_SKRIPTU = [
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox",
    ];

    /**
     * Náhled do mřížky knihovny.
     *
     * Vlastní adresa, ne `raw`: mřížka ukáže i sto dlaždic naráz a stahovat do
     * nich originály by znamenalo stovky megabajtů na jedno otevření obrazovky.
     * Když náhled ještě nevznikl (fronta ho teprve zpracuje), vydá se originál —
     * pomalé je pořád lepší než prázdné místo.
     */
    /**
     * Náhled do mřížky.
     *
     * Na rozdíl od zbytku modulu **není za `auth:sanctum`**, a to z jednoho
     * praktického důvodu: dlaždici stahuje prohlížeč jako obrázek v CSS, kam
     * hlavičku `Authorization` nepřidá, a token v adrese by skončil v historii
     * i v logu. Adresa je proto podepsaná a časově omezená — podpis platí pro
     * jediný soubor a nedá se přepsat na cizí.
     */
    public function thumb(Request $request, string $uuid): StreamedResponse
    {
        $media = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('uuid', $uuid)
            ->first();

        abort_if($media === null, 404, 'Takový soubor tu není.');

        /*
         * Velikost je součástí podpisu (`velikost=velky`), nedá se dopsat.
         *
         * Prohlížeč fotky kreslil tentýž 320px náhled jako mřížka, roztažený na
         * celou obrazovku. Dokud náhledy nevznikaly (viz ImageVariantService),
         * chodil místo něj originál a nebylo to vidět; s náhledy by byla každá
         * otevřená fotka rozmazaná. Upravená verze (otočení, výřez) má přednost.
         */
        $poradi = $request->query('velikost') === 'velky'
            ? ['edited_preview', 'large', 'medium', 'small', 'original', 'video_poster', 'thumbnail']
            : ['edited_thumbnail', 'thumbnail', 'small', 'video_poster', 'original'];

        $varianta = $media->variants()
            ->whereIn('type', $poradi)
            ->get()
            ->sortBy(fn ($v) => array_search($v->type, $poradi, true))
            ->first();

        abort_if($varianta === null, 404, 'Náhled ani originál na disku nejsou.');

        return $this->doprohlizece($varianta->disk, $varianta->path, $media->original_filename, [
            // Náhled se nemění; ať se pro druhou obrazovku nestahuje znovu.
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Přehrání videa.
     *
     * Prohlížeč video stahuje sám, stejně jako obrázek v CSS, a hlavičku
     * `Authorization` k němu nepřidá — adresa je proto podepsaná, ne za
     * tokenem. Podpis platí pro jediný soubor a den.
     *
     * Odpovídá **po částech** (`Range`): bez toho se ve videu nedá přeskakovat
     * a Safari ho nezačne přehrávat vůbec. Pro to je potřeba soubor na disku,
     * takže vzdálené úložiště dostane obyčejný proud — přehraje se od začátku.
     */
    public function video(Request $request, string $uuid): SymfonyResponse
    {
        $media = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('uuid', $uuid)
            ->whereNull('trashed_at')
            ->where('is_hidden', false)
            ->first();

        abort_if($media === null, 404, 'Takový soubor tu není.');
        abort_if($media->media_type !== 'video', 404, 'Tenhle soubor není video.');

        // Kompatibilní převod má přednost: originál bývá v kodeku, který
        // prohlížeč neotevře, a člověk by viděl černou plochu.
        $varianta = $media->variants()
            ->whereIn('type', ['video_compat', 'original'])
            ->orderByRaw("CASE type WHEN 'video_compat' THEN 0 ELSE 1 END")
            ->first();

        abort_if($varianta === null, 404, 'Soubor s videem na disku není.');

        $disk = Storage::disk($varianta->disk);
        $typ = (string) ($media->mime_type ?: 'video/mp4');
        $hlavicky = [
            // Jen video — cokoli jiného by prohlížeč mohl vykreslit jako stránku.
            'Content-Type' => str_starts_with($typ, 'video/') ? $typ : 'video/mp4',
            'Cache-Control' => 'private, max-age=86400',
        ] + self::BEZ_SKRIPTU;

        try {
            $cesta = $disk->path($varianta->path);

            if (is_file($cesta)) {
                return response()->file($cesta, $hlavicky);
            }
        } catch (\Throwable) {
            // Vzdálené úložiště `path()` nemá — spadne se na proud níž.
        }

        return $disk->response($varianta->path, $media->original_filename, $hlavicky);
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

    /**
     * Vybrané fotky jako jeden ZIP.
     *
     * Hromadné „Stáhnout" jen ohlásilo „Připravuji archiv" a nestáhlo nic.
     * Trezor a koš se do archivu nedostanou, cizí fotky se tiše přeskočí.
     */
    public function archiv(Request $request, ArchivMedii $archiv): BinaryFileResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['string', 'max:64'],
        ]);

        $polozky = MediaItem::whereIn('uuid', array_values(array_unique($data['ids'])))
            ->where('gallery_space_id', $this->parId($request))
            ->whereNull('trashed_at')
            ->where('is_hidden', false)
            ->with(['variants' => fn ($q) => $q->where('type', 'original')])
            ->get();

        $odpoved = $archiv->stahnout($polozky, 'vybrane-fotky-'.now()->format('Y-m-d'));

        abort_if($odpoved === null, 404, 'Z výběru aplikace u sebe nemá ani jeden originál.');

        return $odpoved;
    }

    /**
     * Otočení a výřez z prohlížeče fotky — viz `UpravaFotky`.
     *
     * `otoceni: 0, vyrez: false` vrací fotku k originálu.
     */
    public function uprava(Request $request, string $uuid): JsonResponse
    {
        $media = $this->najdi($request, $uuid);

        $data = $request->validate([
            'otoceni' => ['required', 'integer', 'between:-360,360'],
            'vyrez' => ['required', 'boolean'],
        ]);

        abort_unless($media->media_type === 'photo', 422, 'Otáčet a ořezávat jde jen fotky.');

        $hotovo = app(UpravaFotky::class)->uloz($media, (int) $data['otoceni'], (bool) $data['vyrez'], $request->user());

        abort_unless($hotovo, 409, 'Originál tu aplikace nemá — upravit se dá, až se stáhne z Disku.');

        AuditLog::record('media.edit', $media, ['otoceni' => (int) $data['otoceni'], 'vyrez' => (bool) $data['vyrez']]);

        return response()->json(['id' => $media->uuid, 'upraveno' => (int) $data['otoceni'] % 360 !== 0 || (bool) $data['vyrez']]);
    }

    /**
     * Víc položek do koše jedním požadavkem.
     *
     * Hromadné „Do koše" a vyřízení série měnily jen stav v prohlížeči, takže
     * fotky na serveru zůstaly. Po jednom `DELETE` na položku by stovka
     * vybraných fotek vyčerpala limit API (počítadlo je společné) a dávka
     * požadavků najednou už jednou spustila WAF.
     *
     * Cizí a neexistující identifikátory se tiše přeskočí a vrátí se jen ty,
     * které opravdu šly do koše — podle nich klient fotky odebere z knihovny.
     */
    public function destroyMany(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['string', 'max:64'],
        ]);

        $par = $this->parId($request);

        $polozky = MediaItem::whereIn('uuid', array_values(array_unique($data['ids'])))
            ->where('gallery_space_id', $par)
            ->whereNull('trashed_at')
            ->get();

        $zaDni = now()->addDays((int) config('gallery.trash_retention_days', 30));

        MediaItem::whereIn('id', $polozky->pluck('id'))->update([
            'trashed_at' => now(),
            'purge_after' => $zaDni,
        ]);

        return response()->json([
            'ids' => $polozky->pluck('uuid')->values()->all(),
            'status' => 'trashed',
        ]);
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

        /*
         * Do knihovny patří fotky a videa, ne cokoli.
         *
         * Tahle cesta brala jakýkoli soubor: textová poznámka se uložila jako
         * „fotka", zabrala místo v tarifu, dostala dlaždici, kterou nejde
         * zobrazit, a úlohy na náhled a kopii na Disk na ní padaly. Rozhoduje
         * přípona i obsah — přejmenovaný dokument na `.jpg` neprojde.
         */
        $pripona = $this->pripona($jmeno);
        $obsah = (string) (File::mimeType($cesta) ?: '');
        $znamaPripona = in_array($pripona, MediaFormatService::allExtensions(), true);
        // Text, HTML, SVG ani skript nejsou fotka — ani s příponou `.cr2`.
        $spustitelny = str_starts_with($obsah, 'text/') || str_contains($obsah, 'html')
            || str_contains($obsah, 'xml') || str_contains($obsah, 'svg') || str_contains($obsah, 'javascript');
        $obrazNeboVideo = ! $spustitelny && (str_starts_with($obsah, 'image/') || str_starts_with($obsah, 'video/')
            // RAW a HEIC finfo často nepozná; tam musí stačit přípona.
            || MediaFormatService::isRaw($pripona) || in_array($pripona, ['heic', 'heif', 'avif'], true));

        abort_unless($znamaPripona && $obrazNeboVideo, 422, sprintf(
            'Soubor „%s“ není fotka ani video, do knihovny ho nahrát nejde.',
            mb_substr($jmeno, 0, 80),
        ));

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

        /*
         * Kopie na Google Disk hned po nahrání.
         *
         * Původní nahrávání (`UploadController`) ji zařazovalo, tohle ne — a přes
         * tuhle cestu nahrává nové rozhraní všechno. Fotky se tak na Disk
         * dostaly nejdřív v noci, při dorovnání zálohy, a když neběžela fronta,
         * nikdy: doktor hlásil „0 z 203 originálů zkopírováno".
         *
         * Stejně obalené jako ostatní úlohy, a z ostřejšího důvodu: na frontě
         * `sync` běží úloha přímo v požadavku a výpadek Disku nesmí shodit
         * nahrání, které už je v bezpečí. Když prostor žádné napojení nemá,
         * úloha skončí sama (`activeConnection` vrátí nic).
         */
        try {
            MirrorMediaToCloud::dispatch($media->id);
        } catch (\Throwable $e) {
            Log::warning('Kopii na Disk se nepodařilo zařadit', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ——— pomocné ———

    /** Stejný klíč v sezení jako TrezorController a `/files`. */
    private function trezorOdemceny(Request $request): bool
    {
        return $request->hasSession()
            && (int) $request->session()->get('vault_unlocked_until', 0) > now()->timestamp;
    }

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
