<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Services\Media\MazaniFotek;
use App\Services\Media\MediaPurger;
use App\Support\SpaceContext;
use App\Support\Trezor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TrashController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $space = $user->gallerySpaces()->first();

        $media = MediaItem::query()
            ->where('gallery_space_id', $space->id)
            ->whereNotNull('trashed_at')
            // Skryté jen s odemčeným trezorem, stejně jako `vKosi()`. Seznam je
            // dřív vypisoval i zamčené — s názvem souboru i titulkem.
            ->when(! Trezor::odemcen(), fn (Builder $q) => $q->where('is_hidden', false))
            ->with(['variants' => fn ($q) => $q->whereIn('type', ['thumbnail', 'placeholder'])])
            ->orderByDesc('trashed_at')
            ->paginate(60)
            ->through(fn ($m) => $this->formatItem($m));

        $retentionDays = config('gallery.trash_retention_days', 30);

        return Inertia::render('Trash/Index', [
            'media' => $media,
            'retention_days' => $retentionDays,
            // Tlačítko jen tomu, komu ho server opravdu provede.
            'can_purge' => $space !== null && app(MazaniFotek::class)->smiTrvaleMazat($space, $user),
        ]);
    }

    public function restore(Request $request, string $uuid): JsonResponse
    {
        $space = $this->prostorDvojice($request);
        $media = MediaItem::where('uuid', $uuid)
            ->where('gallery_space_id', $space->id)
            ->whereNotNull('trashed_at')
            ->firstOrFail();

        $media->update(['trashed_at' => null, 'purge_after' => null]);
        AuditLog::record('media.restore', $media);

        return response()->json(['status' => 'restored', 'uuid' => $uuid]);
    }

    public function bulkRestore(Request $request): JsonResponse
    {
        $space = $this->prostorDvojice($request);
        $uuids = $request->validate(['uuids' => 'required|array|max:200', 'uuids.*' => 'string'])['uuids'];

        $count = MediaItem::where('gallery_space_id', $space->id)
            ->whereIn('uuid', $uuids)
            ->whereNotNull('trashed_at')
            ->update(['trashed_at' => null, 'purge_after' => null]);

        return response()->json(['count' => $count]);
    }

    public function purge(Request $request, string $uuid): JsonResponse
    {
        $space = $this->prostorSpravce($request);
        $media = $this->vKosi($space)->where('uuid', $uuid)->firstOrFail();

        AuditLog::record('media.purge', $media, ['filename' => $media->original_filename]);

        $this->deleteMediaFiles($media);
        // `forceDelete`, ne `delete`: soft delete by nechal řádek bez souborů,
        // neviditelný pro koš i pro noční úklid.
        $media->forceDelete();

        return response()->json(['status' => 'purged', 'uuid' => $uuid]);
    }

    public function emptyTrash(Request $request): JsonResponse
    {
        $items = $this->vKosi($this->prostorSpravce($request))->get();

        foreach ($items as $item) {
            AuditLog::record('media.purge', $item, ['via' => 'empty_trash']);
            $this->deleteMediaFiles($item);
            $item->forceDelete();
        }

        return response()->json(['count' => $items->count()]);
    }

    private function formatItem(MediaItem $m): array
    {
        return [
            'id' => $m->id,
            'uuid' => $m->uuid,
            'media_type' => $m->media_type,
            'taken_at' => $m->taken_at?->toIso8601String(),
            'trashed_at' => $m->trashed_at?->toIso8601String(),
            'purge_after' => $m->purge_after?->toIso8601String(),
            'width' => $m->width,
            'height' => $m->height,
            'display_title' => $m->display_title ?? $m->original_filename,
            'size_bytes' => $m->size_bytes,
            'variants' => $m->variants->map(fn ($v) => [
                'type' => $v->type,
                'url' => $v->url,
                'dominant_color' => $v->dominant_color,
                'aspect_ratio' => $v->aspect_ratio,
            ]),
        ];
    }

    /**
     * Prostor, jehož koš se trvale maže — a jen když je v něm přihlášený
     * správcem.
     *
     * Dřív rozhodovalo `isAdmin()`, tedy `users.role`, a `owner` má každý
     * zaregistrovaný účet: běžný člen dvojice (`editor`) i kdokoli s vlastním
     * účtem tak mazal nevratně. Oprávnění je role v **tomhle** prostoru.
     */
    private function prostorSpravce(Request $request): GallerySpace
    {
        $space = $request->user()->gallerySpaces()->first();

        abort_if($space === null, 404);
        abort_unless(
            app(MazaniFotek::class)->smiTrvaleMazat($space, $request->user()),
            403,
            'Trvale odstranit smí jen správce prostoru. Do koše to zatím zůstane.'
        );

        return $space;
    }

    /**
     * Prostor, do jehož koše se sahá — a jen když je v něm přihlášený z dvojice.
     *
     * Vrátit z koše je vratné, ale pořád zápis: dřív stačilo, že prostor byl
     * první v seznamu účtu, takže vracel i účet jen pro čtení. Pravidlo je
     * totéž jako `MediaPolicy::restore` a koš prototypu.
     */
    private function prostorDvojice(Request $request): GallerySpace
    {
        $space = $request->user()->gallerySpaces()->first();

        abort_if($space === null, 404);
        abort_unless(
            app(MazaniFotek::class)->jeClenDvojice($space, $request->user()),
            403,
            'Vracet z koše může jen dvojice galerie.'
        );

        return $space;
    }

    /**
     * Co koš ukazuje, s tím se smí nevratně pracovat — nic víc.
     *
     * Jen položky opravdu v koši (dřív šlo trvale smazat i fotku z knihovny)
     * a skryté jen s odemčeným trezorem: „Vysypat koš" by jinak smazal
     * i to, co na obrazovce se zamčeným trezorem nestálo.
     *
     * @return Builder<MediaItem>
     */
    private function vKosi(GallerySpace $space): Builder
    {
        return MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $space->id)
            ->whereNotNull('trashed_at')
            ->when(! Trezor::odemcen(), fn (Builder $q) => $q->where('is_hidden', false));
    }

    /**
     * One implementation, shared with the prototype's own trash screen.
     *
     * Two would mean two places to forget the Drive copy, and a photo the app
     * says is gone would still be sitting in someone's cloud.
     */
    private function deleteMediaFiles(MediaItem $media): void
    {
        app(MediaPurger::class)->purge($media);
    }
}
