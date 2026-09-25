<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\AuditLog;
use App\Models\GuestUpload;
use App\Models\MediaItem;
use App\Models\MediaVariant;
use App\Models\SharedLink;
use App\Services\Sharing\SharedContentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShareController extends Controller
{
    /** Varianty, které sdílená stránka smí dostat — zmenšeniny bez metadat a plakát videa. */
    private const NAHLEDY_STRANKY = ['thumbnail', 'small', 'medium', 'video_poster'];

    /**
     * Kolik špatných hesel k jednomu odkazu za hodinu, než se zamkne.
     *
     * Limit na adresu (`throttle` u cesty) obejde každý s víc adresami —
     * mobilní síť, proxy. Dvacet je víc, než kolik udělá host s překlepy,
     * a na slovník málo.
     */
    private const POKUSU_O_HESLO = 20;

    private const ZAMEK_HESLA_VTERIN = 3600;

    public function __construct(private readonly SharedContentService $sharedContent) {}

    public function index(Request $request): Response
    {
        $shares = SharedLink::where('created_by', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->through(fn (SharedLink $link) => array_merge($link->toArray(), [
                'has_password' => $link->password_hash !== null,
                'target' => $this->sharedContent->summary($link),
            ]));

        return Inertia::render('Shares/Index', ['shares' => $shares]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target_type' => 'required|in:album,media,selection,recipe,place_review',
            'target_id' => 'nullable|integer',
            'target_uuid' => 'nullable|uuid|required_if:target_type,recipe,place_review',
            'name' => 'nullable|string|max:200',
            // Šest znaků, ne čtyři: čtyřmístný kód je deset tisíc možností
            // a odkaz se zkouší bez přihlášení (limit hesla viz `verify()`).
            'password' => 'nullable|string|min:6',
            'expires_at' => 'nullable|date|after:now',
            'allow_download' => 'boolean',
            'allow_guest_upload' => 'boolean',
            'hide_gps' => 'boolean',
            'max_uses' => 'nullable|integer|min:1',
            'media_ids' => 'nullable|array',
            'media_ids.*' => 'integer|exists:media_items,id',
        ]);

        $spaceId = $request->user()->gallerySpaces()->firstOrFail()->id;
        $targetId = $data['target_id'] ?? null;
        $defaultName = null;
        if (in_array($data['target_type'], SharedContentService::CONTENT_TYPES, true)) {
            $target = $this->sharedContent->resolveForSharing($request->user(), $data['target_type'], $data['target_uuid']);
            $spaceId = $target['gallery_space_id'];
            $targetId = $target['id'];
            $defaultName = $target['name'];
        }
        $mediaIds = collect($data['media_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        $validMediaIds = MediaItem::where('gallery_space_id', $spaceId)
            ->where('is_hidden', false)->whereNull('trashed_at')->whereIn('id', $mediaIds)->pluck('id');
        if ($validMediaIds->count() !== $mediaIds->count()) {
            throw ValidationException::withMessages(['media_ids' => 'Výběr obsahuje nedostupnou nebo soukromou položku.']);
        }
        if (($data['target_type'] ?? null) === 'media') {
            $validTarget = MediaItem::where('gallery_space_id', $spaceId)->where('is_hidden', false)->whereNull('trashed_at')->where('id', $data['target_id'] ?? 0)->exists();
            if (! $validTarget) {
                throw ValidationException::withMessages(['target_id' => 'Médium nelze sdílet.']);
            }
        }
        if (($data['target_type'] ?? null) === 'album') {
            $validTarget = Album::where('gallery_space_id', $spaceId)->where('id', $data['target_id'] ?? 0)->exists();
            if (! $validTarget) {
                throw ValidationException::withMessages(['target_id' => 'Album nelze sdílet.']);
            }
        }

        $link = SharedLink::create([
            'created_by' => $request->user()->id,
            'gallery_space_id' => $spaceId,
            'target_type' => $data['target_type'],
            'target_id' => $targetId,
            'name' => $data['name'] ?? $defaultName,
            'password_hash' => isset($data['password']) ? Hash::make($data['password']) : null,
            'expires_at' => $data['expires_at'] ?? null,
            'allow_download' => in_array($data['target_type'], SharedContentService::CONTENT_TYPES, true) ? false : ($data['allow_download'] ?? true),
            'allow_guest_upload' => in_array($data['target_type'], SharedContentService::CONTENT_TYPES, true) ? false : ($data['allow_guest_upload'] ?? false),
            'hide_gps' => in_array($data['target_type'], SharedContentService::CONTENT_TYPES, true) ? true : ($data['hide_gps'] ?? false),
            'show_metadata' => ! in_array($data['target_type'], SharedContentService::CONTENT_TYPES, true),
            'max_uses' => $data['max_uses'] ?? null,
        ]);

        if ($validMediaIds->isNotEmpty()) {
            $link->mediaItems()->attach($validMediaIds);
        }

        AuditLog::record('share.create', $link, ['token' => $link->token, 'target_type' => $link->target_type]);

        return response()->json([
            'id' => $link->id,
            'token' => $link->token,
            'url' => route('share.show', $link->token),
            'expires_at' => $link->expires_at?->toIso8601String(),
        ]);
    }

    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $link = SharedLink::where('token', $token)->firstOrFail();
        $zapocteno = $link->jeZapoctenoV($request);

        if (! $link->isAccessibleFor($zapocteno)) {
            return Inertia::render('Shares/Expired');
        }

        if ($link->password_hash && ! session("share_verified_{$token}")) {
            return Inertia::render('Shares/PasswordGate', ['token' => $token]);
        }

        /*
         * Použití se počítá jednou za návštěvu, ne za každé načtení.
         *
         * `use_count` rostl s každým GET — obnovení stránky i robot, který
         * si odkaz v chatu stáhne kvůli náhledu. Poslední povolený návštěvník
         * pak už nemohl stáhnout fotku, na kterou se právě díval.
         *
         * Přičte se jen tehdy, když je pod limitem, v jednom dotazu: dva
         * návštěvníci naráz by jinak oba prošli přes `max_uses = 1`.
         */
        if (! $zapocteno) {
            if (! $link->zapoctiNavstevu()) {
                return Inertia::render('Shares/Expired');
            }
            $request->session()->put($link->klicZapocteni(), true);
        }

        DB::table('share_access_logs')->insert(['shared_link_id' => $link->id, 'action' => 'view', 'ip_hash' => hash('sha256', (string) $request->ip().config('app.key')), 'user_agent_family' => Str::limit((string) $request->userAgent(), 80, ''), 'created_at' => now()]);

        if (in_array($link->target_type, SharedContentService::CONTENT_TYPES, true)) {
            return Inertia::render('Shares/Content', [
                'link' => [
                    'token' => $link->token,
                    'name' => $link->name,
                    'expires_at' => $link->expires_at?->toIso8601String(),
                ],
                'content' => $this->sharedContent->publicPayload($link),
            ]);
        }

        /*
         * Co host uvidí — výslovně, ne celý model.
         *
         * Stránka dostávala `MediaItem` tak, jak leží v databázi: identifikátory
         * na Google Disku, otisky souborů, id vlastníka, název místa (i se
         * skrytou GPS), a u variant cesty na disku. To vše v HTML stránky,
         * kterou otevře kdokoli s odkazem. Stránka přitom čte jen uuid a adresu
         * náhledu. Fotky v koši se navíc ukazovaly dál — `trashed_at` se tu
         * nekontroloval (u stažení ano).
         */
        $media = $this->mediaOdkazu($link)->with('variants')->limit(200)->get()
            ->map(fn (MediaItem $m) => [
                'uuid' => $m->uuid,
                'media_type' => $m->media_type,
                'variants' => $m->variants
                    /*
                     * Jen náhledy, které stránka opravdu kreslí (`Shares/Show`
                     * bere náhled, jinak originál). Filtr dřív hlídal jen originál,
                     * takže kopie videa k přehrávání (`video_compat`) i `large`
                     * odcházely s podepsanou adresou dál — a kopie videa nesla
                     * metadata zdroje včetně polohy, i u odkazu bez data a místa.
                     *
                     * Originál jen tam, kde nic menšího není — stránka by jinak
                     * neměla co ukázat. S vypnutým stahováním se jinak nevydává.
                     * U odkazu bez data a místa nikdy: nese EXIF i se souřadnicemi.
                     */
                    ->filter(fn ($v) => in_array($v->type, self::NAHLEDY_STRANKY, true)
                        || ($v->type === 'original' && ! $link->hide_gps
                            && $m->variants->whereIn('type', ['thumbnail', 'small', 'medium'])->isEmpty()))
                    ->map(fn ($v) => ['type' => $v->type, 'url' => $v->url, 'width' => $v->width, 'height' => $v->height])
                    ->values(),
            ])
            ->values();

        return Inertia::render('Shares/Show', [
            'link' => [
                'token' => $link->token,
                'name' => $link->name,
                'allow_download' => $link->allow_download,
                'allow_guest_upload' => $link->allow_guest_upload,
                'allow_comments' => $link->allow_comments,
                'show_metadata' => $link->show_metadata,
            ],
            'media' => $media,
            'comments' => $this->vzkazy($link),
        ]);
    }

    /**
     * Vzkazy u odkazu — tak, jak je uvidí host.
     *
     * Bez nich host napsal vzkaz a už ho nikdy neuviděl: stránka se překreslila
     * a po jeho větě nikde nezbyla stopa. Vypadalo to, jako by se nic nestalo,
     * a lidé psali totéž znovu.
     *
     * Schované vzkazy sem nepatří: „schovat" znamená, že to dvojice nechce mít
     * u fotek — ani pro toho, kdo to napsal. Hlasovky taky ne, ty si přehrává
     * dvojice v aplikaci.
     *
     * @return list<array<string, string>>
     */
    private function vzkazy(SharedLink $link): array
    {
        if (! $link->allow_comments || ! Schema::hasTable('guest_comments')) {
            return [];
        }

        return DB::table('guest_comments')
            ->where('shared_link_id', $link->id)
            ->where('is_hidden', false)
            ->where('kind', '!=', 'voice')
            ->whereNotNull('body')
            ->orderBy('created_at')
            ->limit(200)
            ->get(['uuid', 'guest_name', 'body', 'created_at'])
            ->map(fn (object $v) => [
                'id' => (string) $v->uuid,
                'jmeno' => (string) $v->guest_name,
                'text' => (string) $v->body,
                // Česky, ne podle `app.locale`: stránku otevírá babička,
                // které dvojice poslala odkaz, ne správce serveru.
                'kdy' => CarbonImmutable::parse($v->created_at)->locale('cs')->diffForHumans(),
            ])
            ->values()
            ->all();
    }

    public function verify(Request $request, string $token): RedirectResponse
    {
        $link = SharedLink::where('token', $token)->firstOrFail();

        /*
         * Počítadlo na odkaz, ne na adresu.
         *
         * Limit u cesty je na adresu a obejde ho každý, kdo jich má víc.
         * Zamčené je i správné heslo: jinak by odpověď prozradila, kdy se
         * útočník trefil. Úspěch počítadlo vynuluje, ať si host s překlepy
         * nezamkne příští návštěvu.
         */
        $klic = 'sdileni-heslo:'.$link->id;
        if (RateLimiter::tooManyAttempts($klic, self::POKUSU_O_HESLO)) {
            $minut = (int) ceil(RateLimiter::availableIn($klic) / 60);

            abort(429, "K tomuhle odkazu přišlo moc špatných hesel. Zkuste to znovu za {$minut} min.",
                ['Retry-After' => (string) RateLimiter::availableIn($klic)]);
        }

        $password = (string) $request->input('password', '');
        if (! $link->verifyPassword($password)) {
            RateLimiter::hit($klic, self::ZAMEK_HESLA_VTERIN);

            return back()->withErrors(['password' => 'Nesprávné heslo.']);
        }

        RateLimiter::clear($klic);
        session(["share_verified_{$token}" => true]);

        return redirect()->route('share.show', $token);
    }

    public function guestUpload(Request $request, string $token): JsonResponse
    {
        $link = SharedLink::where('token', $token)->firstOrFail();

        // Kdo stránku v tomhle sezení otevřel, nahrává i po vyčerpání `max_uses`.
        if (! $link->isAccessibleFor($link->jeZapoctenoV($request)) || ! $link->allow_guest_upload || ($link->password_hash && ! session("share_verified_{$token}"))) {
            return response()->json(['error' => 'Upload not allowed'], 403);
        }
        $limitKb = (int) floor(min($link->upload_limit_bytes ?: 104857600, 104857600) / 1024);
        $data = $request->validate(['files' => 'required|array|min:1|max:20', 'files.*' => "required|file|max:{$limitKb}|mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,video/mp4,video/quicktime", 'contributor_name' => 'nullable|string|max:100']);

        /*
         * Strop na odkaz, ne jen na soubor.
         *
         * Do schválení leží všechno na disku serveru a nahrát to může kdokoli
         * s odkazem. Limit na soubor to nezastavil: dvacet souborů po sto
         * megabajtech třicetkrát za minutu. Počítá se jen čekající — co dvojice
         * schválila, je už v galerii, a odmítnuté je smazané.
         */
        $strop = (int) config('gallery.guest_upload_pending_mb', 2048) * 1024 * 1024;
        $ceka = (int) GuestUpload::where('shared_link_id', $link->id)->where('status', 'pending')->sum('size_bytes');
        $nove = collect($data['files'])->sum(fn ($soubor) => (int) $soubor->getSize());

        if ($ceka + $nove > $strop) {
            return response()->json([
                'error' => 'upload_quota',
                'message' => 'K tomuhle odkazu už čeká na schválení tolik fotek, kolik se vejde. Až je dvojice projde, půjde nahrávat dál.',
            ], 413);
        }

        $uploads = collect($data['files'])->map(function ($file) use ($link, $data) {
            $uuid = (string) Str::uuid();
            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
            $path = $file->storeAs("guest_uploads/{$uuid}", $safeName, 'local');

            return GuestUpload::create(['uuid' => $uuid, 'shared_link_id' => $link->id, 'original_filename' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size_bytes' => $file->getSize(), 'storage_path' => $path, 'contributor_name' => $data['contributor_name'] ?? null]);
        });
        DB::table('share_access_logs')->insert(['shared_link_id' => $link->id, 'action' => 'guest_upload', 'ip_hash' => hash('sha256', (string) $request->ip().config('app.key')), 'user_agent_family' => Str::limit((string) $request->userAgent(), 80, ''), 'created_at' => now()]);

        return response()->json(['status' => 'pending_review', 'count' => $uploads->count()], 201);
    }

    public function download(Request $request, string $token, string $uuid): StreamedResponse
    {
        $link = SharedLink::where('token', $token)->firstOrFail();
        abort_unless($link->isAccessibleFor($link->jeZapoctenoV($request)) && $link->allow_download && (! $link->password_hash || session("share_verified_{$token}")), 403);
        $item = $this->mediaOdkazu($link)->where('uuid', $uuid)->firstOrFail();
        [$variant, $jmeno] = $link->hide_gps ? $this->kopieBezPolohy($item) : [$item->variants()->where('type', 'original')->firstOrFail(), $item->original_filename];
        DB::table('share_access_logs')->insert(['shared_link_id' => $link->id, 'action' => 'download', 'ip_hash' => hash('sha256', (string) $request->ip().config('app.key')), 'media_item_id' => $item->id, 'created_at' => now()]);

        return Storage::disk($variant->disk)->download($variant->path, $jmeno);
    }

    /**
     * Co stáhnout z odkazu „bez data a místa".
     *
     * Dialog sdílení to slibuje („vypněte, když nechcete prozradit, kde jste
     * byli"), a stažení přitom vydávalo originál i s EXIF — souřadnicemi
     * obvykle domova. EXIF z originálu jen tak smazat nejde: je v něm i otočení
     * snímku a fotka z telefonu by přišla převrácená. Zmenšeniny vznikají už
     * otočené a bez metadat (`ImageVariantService`, `strip: true`), takže se
     * stahuje největší z nich. Video má polohu přímo v souboru — to se nevydá.
     *
     * @return array{0: MediaVariant, 1: string}
     */
    private function kopieBezPolohy(MediaItem $item): array
    {
        abort_if($item->media_type === 'video', 403,
            'U odkazu bez data a místa video stáhnout nejde — poloha je zapsaná přímo v souboru.');

        $kopie = $item->variants()
            ->whereIn('type', ['large', 'medium', 'small'])
            ->orderByRaw("CASE type WHEN 'large' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")
            ->first();

        abort_unless($kopie, 403, 'Kopie bez údajů o místě pro tuhle fotku zatím není.');

        $pripona = pathinfo($kopie->path, PATHINFO_EXTENSION) ?: 'webp';

        return [$kopie, pathinfo((string) $item->original_filename, PATHINFO_FILENAME).'.'.$pripona];
    }

    /** Fotky, které odkaz ukazuje — stejně pro stránku i pro stažení (viz `SharedLink::servedMedia()`). */
    private function mediaOdkazu(SharedLink $link)
    {
        return $link->servedMedia();
    }

    public function destroy(string $id): JsonResponse
    {
        $link = SharedLink::findOrFail($id);

        if ($link->created_by !== request()->user()->id) {
            abort(403);
        }

        AuditLog::record('share.delete', $link);
        $link->delete();

        return response()->json(['status' => 'deleted']);
    }
}
