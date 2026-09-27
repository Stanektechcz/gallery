<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Controller;
use App\Jobs\Media\CalculateMediaHashesJob;
use App\Jobs\Media\GenerateImageVariantsJob;
use App\Jobs\MirrorMediaToCloud;
use App\Models\Album;
use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\MediaVariant;
use App\Services\Billing\EntitlementService;
use App\Services\Media\ArchivMedii;
use App\Services\Media\MediaFormatService;
use App\Services\Media\UpravaFotky;
use App\Services\Media\ZarazeniDoAlba;
use App\Support\Cas;
use App\Support\SpaceContext;
use App\Support\Trezor;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Nahrávání médií z prototypu.
 *
 * Klient (`galerie-api.js`) posílá od září 2026 **všechno po částech** s hlavičkami
 * `X-Upload-Id`, `X-Chunk-Index`, `X-Chunk-Count`, volitelně `X-Taken-At` a `X-Album`;
 * velikost části si zjistí z `limity()`. Vícedílný `store()` zůstává pro starší
 * klienty. S albem se každý hotový soubor rovnou zařadí (viz `zaradDoAlba()`).
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

    /** Kolik klient posílá v jedné části, když mu server nic menšího neřekne. */
    private const VYCHOZI_CAST = 8 * 1024 * 1024;

    /** Menší části už nemají smysl — nahrání videa by trvalo stovky požadavků. */
    private const NEJMENSI_CAST = 256 * 1024;

    /** Rezerva pod `post_max_size` na hlavičky požadavku. */
    private const REZERVA_POZADAVKU = 64 * 1024;

    private const ZPRAVA_ALBUM = 'Album, do kterého se nahrává, už v galerii není. Založte ho znovu.';

    private const ZPRAVA_ZAPIS = 'Server nemohl uložit nahrávaný soubor na disk (nemá právo zápisu do úložiště nebo je plné). '
        .'Nahrávání teď nemá smysl opakovat — je potřeba opravit server.';

    /** Malý soubor jedním požadavkem. */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:512000'],
            'taken_at' => ['nullable'],
            // Nahrávání z telefonu rovnou do alba — viz `albumZPozadavku()`.
            'album' => ['nullable', 'uuid'],
        ]);

        $album = $this->albumZPozadavku($request, $request->input('album'));
        $soubor = $request->file('file');

        return $this->prijmi(
            $request,
            $soubor->getRealPath(),
            $soubor->getClientOriginalName(),
            $request->input('taken_at'),
            $album,
        );
    }

    /**
     * Co server přijme a jestli vůbec může zapisovat.
     *
     * Telefon se ptá před každou dávkou. Velikost části musí projít pod
     * `post_max_size` — jinak PHP tělo zahodí a Laravel odpoví 413 na každou
     * část. A když FPM nesmí psát do `storage` (vlastník root po úloze
     * z cronu), má to telefon říct hned, ne po dvou stech neúspěšných fotkách.
     */
    public function limity(): JsonResponse
    {
        $post = self::bajty((string) ini_get('post_max_size'));
        $cast = self::VYCHOZI_CAST;

        if ($post > 0) {
            $cast = min($cast, $post - self::REZERVA_POZADAVKU);
        }

        $zapis = $this->lzeZapisovat();

        return response()->json([
            'cast' => max(self::NEJMENSI_CAST, $cast),
            'nejvic' => (int) config('gallery.max_upload_size_gb', 32) * 1024 * 1024 * 1024,
            'zapis' => $zapis,
            'zprava' => $zapis ? null : self::ZPRAVA_ZAPIS,
        ]);
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

        /*
         * Album se ověří hned u první části, ne až po přenesení celého videa.
         * U poslední části znovu v `prijmi()` — mezitím ho mohl ten druhý smazat.
         */
        $albumUuid = trim((string) $request->header('X-Album', ''));
        abort_unless($albumUuid === '' || Str::isUuid($albumUuid), 422, self::ZPRAVA_ALBUM);
        if ($poradi === 0 && $albumUuid !== '') {
            $this->albumZPozadavku($request, $albumUuid);
        }

        $disk = Storage::disk(self::CASTI_DISK);
        // Složka patří přihlášenému: cizí nahrávání se stejným identifikátorem
        // si nemůže podstrčit ani přepsat části.
        $adresar = self::CASTI_ADRESAR.'/'.$request->user()->id.'-'.$id;

        $vstup = $request->getContent(true);
        $cestaCasti = $adresar.'/'.str_pad((string) $poradi, 6, '0', STR_PAD_LEFT);
        $zapsano = $disk->writeStream($cestaCasti, $vstup);
        if (is_resource($vstup)) {
            fclose($vstup);
        }

        /*
         * Nezapsaná část.
         *
         * Disk má `throw => false`, takže adresář, do kterého PHP-FPM nesmí
         * psát (vlastník root po úloze spuštěné cronem), vrátil jen `false`
         * a pád přišel až o řádek níž na „Unable to retrieve the file_size" —
         * obecná pětistovka, ze které nikdo nepoznal, že jde o práva na serveru.
         */
        $velikost = $zapsano === false ? null : rescue(fn () => (int) $disk->size($cestaCasti), null, false);
        if ($velikost === null) {
            $this->zapisChybu('Část nahrávaného souboru se nepodařilo zapsat na disk', $disk->path($cestaCasti));
            abort(507, self::ZPRAVA_ZAPIS);
        }

        if ($velikost > self::NEJVETSI_CAST) {
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
            ] + ($poradi === $celkem - 1 ? ['chybi' => $this->chybejiciCasti($casti, $celkem)] : []), 202);
        }

        /*
         * Skládá se vedle částí, na disku aplikace — ne v `sys_get_temp_dir()`.
         *
         * Produkční PHP-FPM má `open_basedir` jen na adresář webu; `tempnam()`
         * v dočasném adresáři systému tam skončil varováním, z něj byla výjimka
         * a poslední část každého velkého souboru odpověděla 500. Tady je to
         * navíc stejný disk jako části i cíl, takže se nic nekopíruje přes
         * hranici souborových systémů.
         */
        $slozeny = $adresar.'.soubor';
        $cely = $disk->path($slozeny);
        $vystup = @fopen($cely, 'wb');

        if ($vystup === false) {
            $this->zapisChybu('Složený soubor se nepodařilo založit', $cely);
            abort(507, self::ZPRAVA_ZAPIS);
        }

        try {
            foreach ($casti as $cast) {
                $zdroj = $disk->readStream($cast);
                $zkopirovano = is_resource($zdroj) ? stream_copy_to_stream($zdroj, $vystup) : false;
                if (is_resource($zdroj)) {
                    fclose($zdroj);
                }
                if ($zkopirovano === false) {
                    fclose($vystup);
                    $disk->deleteDirectory($adresar);
                    $this->zapisChybu('Části se nepodařilo složit', $cely);
                    abort(507, self::ZPRAVA_ZAPIS);
                }
            }
            fclose($vystup);
            $disk->deleteDirectory($adresar);

            return $this->prijmi($request, $cely, $jmeno, $this->casPorizeni($request), $this->albumZHlavicky($request, $albumUuid));
        } finally {
            $disk->delete($slozeny);
        }
    }

    /**
     * Pořadí částí, které serveru ještě chybí.
     *
     * Posílá se jen s poslední částí: telefon podle toho dopošle, co se
     * ztratilo (výpadek spojení, opakovaná poslední část), místo aby celé
     * nahrávání vzdal — nebo ho dřív tiše počítal jako hotové.
     *
     * @param  list<string>  $casti
     * @return list<int>
     */
    private function chybejiciCasti(array $casti, int $celkem): array
    {
        $mame = array_flip(array_map(fn (string $c) => (int) basename($c), $casti));

        return array_values(array_slice(
            array_filter(range(0, $celkem - 1), fn (int $i) => ! isset($mame[$i])),
            0,
            1000,
        ));
    }

    /** Datum pořízení z telefonu (`lastModified` souboru v ms), u částí v hlavičce. */
    private function casPorizeni(Request $request): ?string
    {
        $cas = (string) $request->header('X-Taken-At', '');

        return ctype_digit($cas) ? $cas : null;
    }

    private function albumZHlavicky(Request $request, string $uuid): ?Album
    {
        return $uuid === '' ? null : $this->albumZPozadavku($request, $uuid);
    }

    /**
     * Album, do kterého se nahrává — jen nesmazané album téhož páru.
     *
     * Neexistující nebo cizí album se odmítne dřív, než se soubor uloží:
     * nahrát fotku „do alba" a najít ji pak jen v knihovně by bylo horší
     * než jasná chyba, po které jde album založit znovu.
     */
    private function albumZPozadavku(Request $request, mixed $uuid): ?Album
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $album = Str::isUuid((string) $uuid)
            ? app(ZarazeniDoAlba::class)->najdi($this->parId($request), (string) $uuid)
            : null;

        abort_if($album === null, 422, self::ZPRAVA_ALBUM);

        return $album;
    }

    /**
     * Čas z prohlížeče (ms) jen v rozumném rozsahu.
     *
     * Nula nebo nesmysl z telefonu by na MySQL (`timestamp` od roku 1970)
     * shodil celý zápis — SQLite v testech to pustí. Čas z budoucnosti je
     * špatně nastavené datum v telefonu; EXIF ho stejně přepíše.
     */
    private function platnyCas(mixed $ms): ?Carbon
    {
        if (! is_numeric($ms)) {
            return null;
        }

        $ms = (int) $ms;

        return $ms >= 86_400_000 && $ms <= (now()->timestamp + 86_400) * 1000
            ? Carbon::createFromTimestampMs($ms)
            : null;
    }

    /** Zápis do logu, který sám nesmí shodit odpověď — i log může patřit rootovi. */
    private function zapisChybu(string $zprava, string $cesta): void
    {
        rescue(fn () => Log::error($zprava, ['cesta' => $cesta]), null, false);
    }

    /** Může PHP (na produkci FPM pod `www`) zapisovat tam, kam nahrávání ukládá? */
    private function lzeZapisovat(): bool
    {
        $mista = [
            [Storage::disk(self::CASTI_DISK), self::CASTI_ADRESAR],
            [Storage::disk('public'), 'media'],
        ];

        foreach ($mista as [$disk, $adresar]) {
            $ok = rescue(function () use ($disk, $adresar) {
                if (! $disk->exists($adresar)) {
                    $disk->makeDirectory($adresar);
                }

                return is_writable($disk->path($adresar));
            }, false, false);

            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /** `8M`, `1G`, `512K` z php.ini na bajty; `0` = bez omezení. */
    private static function bajty(string $hodnota): int
    {
        $hodnota = trim($hodnota);
        $cislo = (int) $hodnota;

        return match (strtolower(substr($hodnota, -1))) {
            'g' => $cislo * 1024 * 1024 * 1024,
            'm' => $cislo * 1024 * 1024,
            'k' => $cislo * 1024,
            default => $cislo,
        };
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

        /*
         * Ani z koše. Vyhozenou fotku šlo dál stahovat, jako by se nic nestalo
         * — náhled, video i archiv koš dávno nevydávají a obrazovka koše
         * originál nepotřebuje (stahuje se jen z knihovny a u dokladu).
         */
        abort_if($media->trashed_at !== null, 404, 'Takový soubor tu není.');

        $originál = $media->variants()->where('type', 'original')->first();

        abort_if($originál === null, 404, 'Originál tohoto souboru na disku není.');

        /*
         * Originál z trezoru se neukládá do paměti prohlížeče.
         *
         * Po zamčení trezoru by ho prohlížeč vydal z mezipaměti komukoli
         * u téhož počítače — `no-cache` jen chce ověření, uložení nezakáže.
         */
        return $this->doprohlizece($originál->disk, $originál->path, $media->original_filename,
            $media->is_hidden ? ['Cache-Control' => 'private, no-store'] : []);
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
    private function doprohlizece(string $disk, string $cesta, string $jmeno, array $dalsi = [], bool $vlastniSvg = false): StreamedResponse
    {
        $typ = (string) (rescue(fn () => Storage::disk($disk)->mimeType($cesta), null, false) ?: 'application/octet-stream');
        // SVG jen tehdy, když ho nakreslil server sám — viz `vlastniZastupce()`.
        if ($vlastniSvg) {
            $typ = 'image/svg+xml';
        }
        $zobrazit = (str_starts_with($typ, 'image/') && (! str_contains($typ, 'svg') || $vlastniSvg))
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
        /*
         * Trezor náhledy nevydává nikdy (viz System::obsahTrezoru) — podepsaná
         * adresa vydaná dřív, než fotka do trezoru odešla, by jinak platila
         * do konce zítřka.
         */
        $media = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('uuid', $uuid)
            ->where('is_hidden', false)
            /*
             * Ani koš. Fotka vyhozená do koše šla přes dřív vydanou podepsanou
             * adresu (sdílený odkaz, chat, přeposlaná zpráva) otevřít až do
             * konce zítřka. Koš sám náhledy nekreslí a po obnovení přijde
             * adresa nová — video to tak dělalo odjakživa.
             */
            ->whereNull('trashed_at')
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
        $velky = $request->query('velikost') === 'velky';
        $poradi = $velky
            ? ['edited_preview', 'large', 'medium', 'small', 'original', 'video_poster', 'thumbnail']
            : ['edited_thumbnail', 'thumbnail', 'small', 'video_poster', 'original'];

        $varianty = $media->variants()->whereIn('type', $poradi)->get();

        /*
         * Originál, který prohlížeč nevykreslí (HEIC, TIFF, RAW), až úplně
         * nakonec. Prohlížeč fotky ho dostával místo chybějící zmenšeniny
         * a Chrome z něj ukázal jen podklad `#111` — černý čtverec. Menší
         * náhled, který se vykreslí, je lepší; originál zůstává poslední
         * možností, kdyby nic jiného nebylo.
         */
        $varianta = $this->prvniNaDisku($varianty, $poradi,
            fn ($v) => $v->type !== 'original' || self::obrazekProProhlizec((string) ($v->mime_type ?: $media->mime_type)))
            ?? $this->prvniNaDisku($varianty, $poradi);

        abort_if($varianta === null, 404, 'Náhled ani originál na disku nejsou.');

        if ($velky && $media->media_type === 'photo' && ! in_array($varianta->type, self::VELKE_NAHLEDY, true)) {
            $this->doplnVarianty($media);
        }

        return $this->doprohlizece($varianta->disk, $varianta->path, $media->original_filename, [
            // Náhled se nemění; ať se pro druhou obrazovku nestahuje znovu.
            'Cache-Control' => 'private, max-age=86400',
        ], self::vlastniZastupce($media, $varianta));
    }

    /** Zmenšeniny, které stačí na celou obrazovku prohlížeče fotky. */
    private const VELKE_NAHLEDY = ['edited_preview', 'large', 'medium'];

    /** Obrázky, které vykreslí každý běžný prohlížeč. HEIC umí jen Safari. */
    private const OBRAZKY_PRO_PROHLIZEC = ['image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp'];

    private static function obrazekProProhlizec(string $typ): bool
    {
        return in_array(strtolower(trim($typ)), self::OBRAZKY_PRO_PROHLIZEC, true);
    }

    /**
     * První varianta v daném pořadí, jejíž soubor na disku opravdu je.
     *
     * Brala se první podle záznamu v databázi. Starší nasazení ale zakládala
     * záznam i tehdy, když se soubor nezapsal (viz `gallery:thumbnails`),
     * a soubor mohl zmizet i jinak — pak `Storage::response()` spadl na
     * „Unable to retrieve the file_size" a z adresy byla pětistovka. Mřížka
     * (`thumbnail`) přitom fungovala dál, takže to vypadalo, že se nenačítá
     * jen detail: černý čtverec místo fotky i videa.
     *
     * @param  Collection<int, MediaVariant>  $varianty
     * @param  list<string>  $poradi
     * @param  (\Closure(MediaVariant): bool)|null  $smi
     */
    private function prvniNaDisku(Collection $varianty, array $poradi, ?\Closure $smi = null): ?MediaVariant
    {
        return $varianty
            ->sortBy(fn ($v) => array_search($v->type, $poradi, true))
            ->first(fn ($v) => ($smi === null || $smi($v))
                && rescue(fn () => Storage::disk($v->disk)->exists($v->path), false, false));
    }

    /**
     * Fotka bez velké zmenšeniny si ji nechá dodělat — jednou za půl hodiny.
     *
     * Stejně jako náhledy v `MediaFileController::missingPreviewResponse()`:
     * `gallery:thumbnails` dřív doplňoval jen `thumbnail`, takže prohlížeč
     * fotky u starších fotek neměl co ukázat v plné velikosti.
     */
    private function doplnVarianty(MediaItem $media): void
    {
        if (Cache::add('gallery:variant-repair:'.$media->id, true, now()->addMinutes(30))) {
            GenerateImageVariantsJob::dispatch($media->id)->onQueue('media');
        }
    }

    /**
     * Zástupný obrázek videa, který kreslí server sám (`generateFallbackPoster`).
     *
     * Je to SVG a `doprohlizece()` SVG jinak vydává jako přílohu — plakát
     * videa i dlaždice pak zůstaly prázdné (v prohlížeči černé). Tenhle
     * soubor ale nahrát nejde: vzniká jen na téhle cestě a jen jako
     * `video_poster` nebo `thumbnail`. Politika `sandbox` z `BEZ_SKRIPTU`
     * platí i pro něj.
     */
    private static function vlastniZastupce(MediaItem $media, MediaVariant $varianta): bool
    {
        return in_array($varianta->type, ['video_poster', 'thumbnail'], true)
            && $varianta->format === 'svg'
            && $varianta->path === 'media/'.$media->uuid.'/video_placeholder.svg';
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
        // Ze souborů, které na disku opravdu jsou — záznam převodu bez souboru
        // dřív shodil přehrání na pětistovku, i když originál ležel vedle.
        $poradi = ['video_compat', 'original'];
        $varianta = $this->prvniNaDisku($media->variants()->whereIn('type', $poradi)->get(), $poradi);

        abort_if($varianta === null, 404, 'Soubor s videem na disku není.');

        $disk = Storage::disk($varianta->disk);
        /*
         * Typ podle vydávaného souboru, ne podle originálu. Převod je vždycky
         * MP4 (H.264 + AAC), ale posílal se s typem originálu — u videa
         * z iPhonu `video/quicktime`. S `nosniff` to Safari i část ostatních
         * prohlížečů odmítly a přehrávač zůstal černý.
         */
        $typ = $varianta->type === 'video_compat'
            ? 'video/mp4'
            : (string) ($varianta->mime_type ?: $media->mime_type ?: 'video/mp4');
        $hlavicky = [
            // Jen video — cokoli jiného by prohlížeč mohl vykreslit jako stránku.
            'Content-Type' => str_starts_with($typ, 'video/') ? $typ : 'video/mp4',
            'Cache-Control' => 'private, max-age=86400',
        ] + self::BEZ_SKRIPTU;

        try {
            $cesta = $disk->path($varianta->path);

            if (is_file($cesta)) {
                /*
                 * `BinaryFileResponse` si po nastavení hlaviček sám přepne
                 * odpověď na `public` a naše `private` přepíše — video dvojice
                 * by pak směla uložit i sdílená mezipaměť po cestě.
                 */
                return response()->file($cesta, $hlavicky)->setPrivate();
            }
        } catch (\Throwable) {
            // Vzdálené úložiště `path()` nemá — spadne se na proud níž.
        }

        return $disk->response($varianta->path, $media->original_filename, $hlavicky);
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

        $odpoved = $archiv->stahnout($polozky, 'vybrane-fotky-'.Cas::dnes()->toDateString());

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
        // Úprava přepisuje náhledy na disku — účet jen pro čtení ji dělat nesmí,
        // stejně jako mazání (`MazaniFotek`) a zápisy přes stav.
        abort_if((bool) $request->user()?->read_only_mode, 403, 'Účet je v režimu jen pro čtení.');

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

    /*
     * „Do koše" (`DELETE media/{uuid}`, `POST media/do-kose`) tu bylo a mazalo
     * rovnou — i fotky z trezoru se zamčeným trezorem. Dvojice se ale dohodla
     * mazat jen po společném schválení, takže obojí je teď v MazaniController
     * nad `MazaniFotek`.
     */

    // ——— přijetí souboru ———

    /**
     * Hotový soubor na disku → řádek v knihovně.
     *
     * @param  string  $cesta  Úplný soubor, ne část
     * @param  mixed  $takenAt  Čas poslední změny souboru z prohlížeče (ms)
     */
    private function prijmi(Request $request, string $cesta, string $jmeno, $takenAt, ?Album $album = null): JsonResponse
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
            /*
             * Duplicita do alba patří taky — opakované nahrání složky má
             * skončit úplným albem. Jen ne fotka z trezoru: ta do sdíleného
             * alba nesmí, ani když ji někdo nahraje znovu.
             */
            $doAlba = $album !== null && ! $stavajici->is_hidden ? $album : null;

            return response()->json($this->naKlienta($stavajici, 'duplicate') + $this->zaradDoAlba($request, $stavajici, $doAlba));
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
            'taken_at' => $this->platnyCas($takenAt),
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

        return response()->json($this->naKlienta($media, 'stored') + $this->zaradDoAlba($request, $media, $album), 201);
    }

    /**
     * Hotová fotka do alba, ze kterého se nahrává.
     *
     * Hned po uložení, ne až na konci dávky: zavřený prohlížeč uprostřed
     * nahrávání dvou set fotek by jinak nechal hotové fotky mimo album.
     * Nepovedené zařazení nesmí shodit už uložený originál — klient dostane
     * `album: null` a větu, co se stalo.
     *
     * @return array{album?: string|null, album_zprava?: string}
     */
    private function zaradDoAlba(Request $request, MediaItem $media, ?Album $album): array
    {
        if ($album === null) {
            return $request->filled('album') || $request->hasHeader('X-Album') ? ['album' => null] : [];
        }

        try {
            DB::transaction(fn () => app(ZarazeniDoAlba::class)->zarad($album, $media->gallery_space_id, [$media->uuid], $request->user()->id));

            return ['album' => $album->uuid];
        } catch (\Throwable $e) {
            rescue(fn () => Log::warning('Nahranou fotku se nepodařilo zařadit do alba', [
                'media_id' => $media->id, 'album_id' => $album->id, 'chyba' => $e->getMessage(),
            ]), null, false);

            return ['album' => null, 'album_zprava' => 'Soubor je nahraný, ale do alba se zařadit nepodařilo.'];
        }
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
        /*
         * Jeden řetěz, ne tři úlohy vedle sebe.
         *
         * Otisky zařadí metadata a metadata náhledy (u videa plakát). Zařazovat
         * sem náhledy i metadata zvlášť znamenalo počítat náhledy dvakrát
         * — a dvakrát z nich startovat nahrávání na Disk.
         */
        try {
            CalculateMediaHashesJob::dispatch($media->id)->onQueue('media');
        } catch (\Throwable $e) {
            Log::warning('Doplňkové zpracování média se nepodařilo zařadit', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
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
         *
         * Nahrávání na Disk zařadí i konec řetězu náhledů (kvůli připojením
         * bez `gallery_space_id`, která najde jen DriveConnectionResolver);
         * že z toho na Disku nevzniknou dvě kopie, hlídá sama
         * InitiateDriveResumableUploadJob.
         */
        try {
            // Trezor do cloudu nejde (rozhodnutí 27. 9. 2026); úloha to hlídá
            // znovu sama.
            if (! $media->is_hidden) {
                MirrorMediaToCloud::dispatch($media->id);
            }
        } catch (\Throwable $e) {
            Log::warning('Kopii na Disk se nepodařilo zařadit', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ——— pomocné ———

    /** Tatáž podmínka jako TrezorController a `/files` — viz `Trezor`. */
    private function trezorOdemceny(Request $request): bool
    {
        return Trezor::odemcen($request);
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
