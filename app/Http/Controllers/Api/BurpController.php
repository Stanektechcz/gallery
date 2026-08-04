<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Burp;
use App\Models\BurpRating;
use App\Models\GallerySpace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The first paid add-on. Route registration applies `module:burps`, so everything here
 * is already behind the entitlement check.
 */
class BurpController extends Controller
{
    private const DISK = 'local';
    private const ALLOWED_MIME = ['audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/x-m4a', 'audio/aac'];
    private const CRITERIA = ['loudness', 'length', 'artistry', 'surprise'];

    public function index(Request $request): JsonResponse
    {
        $this->available();
        $user = $request->user();
        $space = $this->space($request, $request->integer('gallery_space_id') ?: null);

        $burps = Burp::with(['author:id,name', 'ratings.user:id,name'])
            ->where('gallery_space_id', $space->id)
            ->orderByDesc('happened_at')->orderByDesc('id')
            ->limit(200)->get();

        return response()->json([
            'space_id' => $space->id,
            'burps' => $burps->map(fn (Burp $burp) => $this->payload($burp, $user->id))->values(),
            'leaderboard' => $this->leaderboard($burps),
            'champion' => $this->championOfMonth($burps),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->available();
        $this->write($request);
        $data = $request->validate([
            'gallery_space_id' => 'nullable|integer',
            'title' => 'nullable|string|max:180',
            'occasion' => 'nullable|string|max:120',
            'happened_at' => 'nullable|date',
            'duration_ms' => 'nullable|integer|between:100,120000',
            'audio' => 'nullable|file|max:10240|mimetypes:' . implode(',', self::ALLOWED_MIME),
        ]);

        $space = $this->space($request, $data['gallery_space_id'] ?? null);
        $path = null;
        $mime = null;
        $size = null;
        if ($request->hasFile('audio')) {
            $file = $request->file('audio');
            $path = $file->store("burps/{$space->id}", self::DISK);
            abort_unless($path, 500, 'Nahrávku se nepodařilo uložit.');
            $mime = $file->getClientMimeType();
            $size = $file->getSize();
        }

        $burp = Burp::create([
            'gallery_space_id' => $space->id,
            'created_by' => $request->user()->id,
            'title' => $data['title'] ?? null,
            'occasion' => $data['occasion'] ?? null,
            'duration_ms' => $data['duration_ms'] ?? null,
            'path' => $path, 'mime_type' => $mime, 'size_bytes' => $size,
            'happened_at' => isset($data['happened_at']) ? Carbon::parse($data['happened_at']) : now(),
        ]);

        return response()->json($this->payload($burp->fresh(['author', 'ratings.user']), $request->user()->id), 201);
    }

    /** One rating per person per burp; rating your own is not allowed. */
    public function rate(Request $request, string $uuid): JsonResponse
    {
        $this->available();
        $this->write($request);
        $burp = $this->burp($request, $uuid);
        abort_if($burp->created_by === $request->user()->id, 422, 'Vlastní krkanec si ohodnotit nemůžete.');

        $rules = ['comment' => 'nullable|string|max:400'];
        foreach (self::CRITERIA as $criterion) $rules[$criterion] = 'required|integer|between:1,5';
        $data = $request->validate($rules);

        $score = round(array_sum(array_map(fn ($key) => (int) $data[$key], self::CRITERIA)) / count(self::CRITERIA), 2);
        BurpRating::updateOrCreate(
            ['burp_id' => $burp->id, 'user_id' => $request->user()->id],
            collect($data)->only([...self::CRITERIA, 'comment'])->all() + ['score' => $score]
        );

        return response()->json($this->payload($burp->fresh(['author', 'ratings.user']), $request->user()->id));
    }

    public function stream(Request $request, string $uuid): StreamedResponse
    {
        $this->available();
        $burp = $this->burp($request, $uuid);
        abort_unless($burp->path && Storage::disk(self::DISK)->exists($burp->path), 404);

        return Storage::disk(self::DISK)->response($burp->path, null, [
            'Content-Type' => $burp->mime_type ?? 'audio/webm',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->available();
        $this->write($request);
        $burp = $this->burp($request, $uuid);
        abort_unless($burp->created_by === $request->user()->id, 403, 'Smazat záznam může jen jeho autor.');

        if ($burp->path) Storage::disk(self::DISK)->delete($burp->path);
        $burp->delete();   // ratings cascade

        return response()->json(['deleted' => true]);
    }

    private function payload(Burp $burp, int $viewerId): array
    {
        $ratings = $burp->ratings;
        $mine = $ratings->firstWhere('user_id', $viewerId);

        return [
            'uuid' => $burp->uuid,
            'title' => $burp->title,
            'occasion' => $burp->occasion,
            'duration_ms' => $burp->duration_ms,
            'happened_at' => optional($burp->happened_at)->toIso8601String(),
            'author' => ['id' => $burp->author?->id, 'name' => $burp->author?->name],
            'has_audio' => (bool) $burp->path,
            'stream_url' => $burp->path ? "/api/v1/burps/{$burp->uuid}/stream" : null,
            'average_score' => $ratings->count() ? round($ratings->avg('score'), 2) : null,
            'ratings' => $ratings->map(fn (BurpRating $rating) => [
                'user' => ['id' => $rating->user?->id, 'name' => $rating->user?->name],
                'loudness' => $rating->loudness, 'length' => $rating->length,
                'artistry' => $rating->artistry, 'surprise' => $rating->surprise,
                'score' => $rating->score, 'comment' => $rating->comment,
            ])->values(),
            'my_rating' => $mine ? [
                'loudness' => $mine->loudness, 'length' => $mine->length,
                'artistry' => $mine->artistry, 'surprise' => $mine->surprise, 'comment' => $mine->comment,
            ] : null,
            'can_rate' => $burp->created_by !== $viewerId,
            'can_delete' => $burp->created_by === $viewerId,
        ];
    }

    /** Ranked by average score; only rated burps count. */
    private function leaderboard($burps): array
    {
        return $burps->filter(fn (Burp $burp) => $burp->ratings->count() > 0)
            ->groupBy('created_by')
            ->map(fn ($group) => [
                'user' => ['id' => $group->first()->author?->id, 'name' => $group->first()->author?->name],
                'burps' => $group->count(),
                'average_score' => round($group->flatMap->ratings->avg('score'), 2),
                'best_score' => round($group->flatMap->ratings->max('score'), 2),
            ])
            ->sortByDesc('average_score')->values()->all();
    }

    private function championOfMonth($burps): ?array
    {
        $month = $burps->filter(fn (Burp $burp) => $burp->happened_at?->isSameMonth(now()) && $burp->ratings->count() > 0);
        if ($month->isEmpty()) return null;

        $best = $month->sortByDesc(fn (Burp $burp) => $burp->ratings->avg('score'))->first();

        return [
            'user' => ['id' => $best->author?->id, 'name' => $best->author?->name],
            'title' => $best->title,
            'score' => round($best->ratings->avg('score'), 2),
            'happened_at' => optional($best->happened_at)->toIso8601String(),
        ];
    }

    private function burp(Request $request, string $uuid): Burp
    {
        return Burp::with(['author:id,name', 'ratings.user:id,name'])->where('uuid', $uuid)
            ->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))
            ->firstOrFail();
    }

    private function space(Request $request, ?int $id): GallerySpace
    {
        $query = GallerySpace::whereHas('members', fn ($members) => $members->whereKey($request->user()->id));

        return $id ? $query->findOrFail($id) : $query->orderByDesc('is_default')->firstOrFail();
    }

    private function write(Request $request): void
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze zapisovat.');
    }

    private function available(): void
    {
        abort_unless(Schema::hasTable('burps') && Schema::hasTable('burp_ratings'), 503, 'Pro tento modul dokončete databázové migrace.');
    }
}
