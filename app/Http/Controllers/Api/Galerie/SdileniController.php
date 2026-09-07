<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\SharedLink;
use App\Services\Obsah\Sdileni;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Sdílený odkaz, který opravdu vznikne.
 *
 * Obrazovka slibovala odkaz, který někomu pošlete — a celý ho držela ve stavu
 * prohlížeče. Po odhlášení zmizel, druhý z dvojice o něm nevěděl a otevřít ho
 * nešlo nikdy: token se nikde nezaložil. Byla to největší díra ze všech,
 * protože o téhle jedné věci ta obrazovka celá je.
 *
 * Nepíše se do `/api/state` jako jinde: odkaz má vlastní tabulku, token
 * a expiraci, a jeho adresu musí vrátit server. Ta z prohlížeče by mířila
 * nikam.
 */
class SdileniController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    /** Volby expirace tak, jak je nabízí dialog. */
    private const EXPIRACE = ['1' => 1, '7' => 7, '30' => 30, 'nikdy' => null];

    public function __construct(private readonly Sdileni $obsah) {}

    public function store(Request $request): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $data = $this->overeno($request);

        [$druh, $cil, $polozky] = $this->cil($request, $prostor);

        $odkaz = SharedLink::create([
            'created_by' => $request->user()?->id,
            'gallery_space_id' => $prostor->id,
            'target_type' => $druh,
            'target_id' => $cil,
            'name' => $data['name'],
            'description' => $data['zprava'],
            'password_hash' => $data['heslo'] !== null ? Hash::make($data['heslo']) : null,
            'expires_at' => $this->expirace($data['expirace']),
            'allow_download' => $data['stahovani'],
            'allow_guest_upload' => $data['hoste'],
            'allow_comments' => $data['komentare'],
            'show_metadata' => $data['metadata'],
            'hide_gps' => ! $data['metadata'],
            'is_active' => true,
        ]);

        if ($polozky->isNotEmpty()) {
            $odkaz->mediaItems()->attach($polozky);
        }

        AuditLog::record('share.create', $odkaz, ['token' => $odkaz->token, 'target_type' => $odkaz->target_type]);

        return $this->odpoved($prostor, 'Odkaz vytvořen.', $odkaz);
    }

    public function update(Request $request, int $odkaz): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $radek = $this->najdi($prostor, $odkaz);
        $data = $this->overeno($request);

        $zmeny = [
            'name' => $data['name'],
            'description' => $data['zprava'],
            'expires_at' => $this->expirace($data['expirace']),
            'allow_download' => $data['stahovani'],
            'allow_guest_upload' => $data['hoste'],
            'allow_comments' => $data['komentare'],
            'show_metadata' => $data['metadata'],
            'hide_gps' => ! $data['metadata'],
        ];

        /*
         * Heslo se přepisuje, jen když nějaké přišlo.
         *
         * Dialog při úpravě heslo nezná — server ho neposílá a poslat nemůže.
         * Kdyby se přebíralo prázdné pole, otevřelo by uložení jiné změny
         * odkaz všem, kdo znají adresu.
         */
        if ($data['heslo'] !== null) {
            $zmeny['password_hash'] = Hash::make($data['heslo']);
        } elseif ($request->boolean('bez_hesla')) {
            $zmeny['password_hash'] = null;
        }

        $radek->update($zmeny);

        AuditLog::record('share.update', $radek, ['token' => $radek->token]);

        return $this->odpoved($prostor, 'Nastavení sdílení uloženo.', $radek);
    }

    /**
     * Prodloužit platnost odkazu o třicet dní.
     *
     * Tlačítko „Prodloužit o 30 dní" ve statistice odkazu jen ohlásilo
     * „Expirace prodloužena" a odkaz vypršel přesně tak, jak měl. Je to
     * přitom to, čím člověk zachraňuje odkaz, který má někomu ještě
     * fungovat — třeba babičce, která si fotky ještě nestáhla.
     *
     * Počítá se od **pozdějšího z dneška a stávající platnosti**: u odkazu,
     * který platí ještě měsíc, by prodloužení od dneška byla ve skutečnosti
     * zkrácení.
     */
    public function prodluz(Request $request, int $odkaz): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $radek = $this->najdi($prostor, $odkaz);

        $od = $radek->expires_at && $radek->expires_at->isFuture()
            ? CarbonImmutable::parse($radek->expires_at)
            : CarbonImmutable::now();

        $radek->update(['expires_at' => $od->addDays(30), 'is_active' => true]);

        AuditLog::record('share.extend', $radek, ['token' => $radek->token]);

        return response()->json([
            'ok' => true,
            'id' => $radek->id,
            'plati_do' => $radek->expires_at->format('j. n. Y'),
            'zprava' => 'Platnost odkazu prodloužena o 30 dní.',
        ]);
    }

    public function destroy(Request $request, int $odkaz): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $radek = $this->najdi($prostor, $odkaz);

        AuditLog::record('share.revoke', $radek, ['token' => $radek->token]);
        $radek->delete();

        return $this->odpoved($prostor, 'Odkaz zneplatněn.');
    }

    /**
     * Co dialog poslal.
     *
     * @return array<string, mixed>
     */
    private function overeno(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'expirace' => ['required', 'string', 'in:'.implode(',', array_keys(self::EXPIRACE))],
            // Šest znaků, jak hlídá i dialog. Kratší heslo u fotek, které
            // dvojice někomu poslala, není ochrana, ale zdržení.
            'heslo' => ['nullable', 'string', 'min:6', 'max:200'],
            'zprava' => ['nullable', 'string', 'max:2000'],
            'stahovani' => ['boolean'],
            'metadata' => ['boolean'],
            'komentare' => ['boolean'],
            'hoste' => ['boolean'],
            'album' => ['nullable', 'uuid'],
            'polozky' => ['nullable', 'array', 'max:500'],
            'polozky.*' => ['uuid'],
        ]);

        return [
            'name' => trim($data['name']),
            'expirace' => $data['expirace'],
            'heslo' => ($data['heslo'] ?? '') !== '' ? $data['heslo'] : null,
            'zprava' => trim((string) ($data['zprava'] ?? '')) ?: null,
            'stahovani' => $request->boolean('stahovani'),
            'metadata' => $request->boolean('metadata'),
            'komentare' => $request->boolean('komentare'),
            'hoste' => $request->boolean('hoste'),
        ];
    }

    /**
     * Co se sdílí: vybrané položky, nebo celé album.
     *
     * Odkaz bez obsahu se nezakládá — byla by to adresa, na které není nic,
     * a dvojice by ji někomu poslala.
     *
     * @return array{0: string, 1: ?int, 2: Collection<int, int>}
     */
    private function cil(Request $request, GallerySpace $prostor): array
    {
        $uuidy = collect($request->input('polozky', []))->filter()->unique()->values();

        if ($uuidy->isNotEmpty()) {
            $polozky = MediaItem::withoutGlobalScopes()
                ->where('gallery_space_id', $prostor->id)
                ->whereNull('trashed_at')
                ->where('is_hidden', false)
                ->whereIn('uuid', $uuidy)
                ->pluck('id');

            abort_if($polozky->isEmpty(), 422, 'Vybrané položky se sdílet nedají — jsou smazané, nebo v trezoru.');

            return ['selection', null, $polozky];
        }

        $album = $request->input('album');

        if ($album && Schema::hasTable('albums')) {
            $id = Album::withoutGlobalScopes()
                ->where('gallery_space_id', $prostor->id)
                ->where('uuid', $album)
                ->value('id');

            if ($id !== null) {
                return ['album', (int) $id, collect()];
            }
        }

        abort(422, 'Odkaz potřebuje vědět, co má ukázat — vyberte položky nebo album.');
    }

    private function najdi(GallerySpace $prostor, int $id): SharedLink
    {
        return SharedLink::where('gallery_space_id', $prostor->id)->findOrFail($id);
    }

    private function expirace(string $volba): ?CarbonImmutable
    {
        $dni = self::EXPIRACE[$volba] ?? null;

        return $dni === null ? null : CarbonImmutable::now()->addDays($dni);
    }

    private function odpoved(GallerySpace $prostor, string $zprava, ?SharedLink $odkaz = null): JsonResponse
    {
        return response()->json(array_filter([
            'zprava' => $zprava,
            'ok' => true,
            // Celá adresa: zkrácená podoba v tabulce se nedá nikomu poslat.
            'odkaz' => $odkaz ? route('share.show', $odkaz->token) : null,
            'id' => $odkaz?->id,
        ], fn ($v) => $v !== null) + $this->obsahPoAkci($this->obsah, $prostor));
    }
}
