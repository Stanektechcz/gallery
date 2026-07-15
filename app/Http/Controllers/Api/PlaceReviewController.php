<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Drive\CreateDriveFolderJob;
use App\Models\Album;
use App\Models\AuditLog;
use App\Models\MediaItem;
use App\Models\Place;
use App\Models\PlaceReview;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlaceReviewController extends Controller
{
    private const CRITERIA = [
        'overall' => 'Celkem',
        'service' => 'Obsluha',
        'staff_friendliness' => 'Vstřícnost',
        'food' => 'Jídlo',
        'food_quality' => 'Kvalita jídla',
        'drink' => 'Pití',
        'speed' => 'Rychlost',
        'menu' => 'Nabídka',
        'atmosphere' => 'Atmosféra',
        'cleanliness' => 'Čistota',
        'value' => 'Cena/výkon',
    ];

    public function index(Request $request, Place $place): JsonResponse
    {
        $space = $this->space($request, $place);
        $filters = $request->validate([
            'q' => 'nullable|string|max:120',
            'context' => 'nullable|in:breakfast,lunch,dinner,coffee,dessert,drinks,takeaway,delivery,other',
            'author_user_id' => 'nullable|integer',
            'min_rating' => 'nullable|numeric|between:1,5',
        ]);

        $reviews = PlaceReview::query()
            ->where('place_id', $place->id)
            ->where(fn ($query) => $query->where('status', 'published')->orWhere('author_user_id', $request->user()->id))
            ->when($filters['context'] ?? null, fn ($query, $context) => $query->where('visit_context', $context))
            ->when($filters['author_user_id'] ?? null, fn ($query, $author) => $query->where('author_user_id', $author))
            ->when($filters['min_rating'] ?? null, fn ($query, $rating) => $query->where('overall_rating', '>=', $rating))
            ->when(trim((string) ($filters['q'] ?? '')), function ($query, $term) {
                $like = '%' . trim($term) . '%';
                $query->where(fn ($search) => $search
                    ->where('positives', 'like', $like)
                    ->orWhere('improvements', 'like', $like)
                    ->orWhere('notes', 'like', $like)
                    ->orWhereHas('items', fn ($items) => $items->where('name', 'like', $like)->orWhere('note', 'like', $like)));
            })
            ->with(['author:id,name', 'items', 'media.variants'])
            ->orderByDesc('visited_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $published = PlaceReview::query()->where('place_id', $place->id)->where('status', 'published');
        $select = ['COUNT(*) AS review_count', 'COUNT(DISTINCT author_user_id) AS reviewers_count'];
        foreach (array_keys(self::CRITERIA) as $criterion) {
            $select[] = "AVG({$criterion}_rating) AS {$criterion}_average";
        }
        $select[] = 'AVG(CASE WHEN would_return = 1 THEN 100.0 WHEN would_return = 0 THEN 0.0 ELSE NULL END) AS return_percent';
        $select[] = 'AVG(CASE WHEN recommends = 1 THEN 100.0 WHEN recommends = 0 THEN 0.0 ELSE NULL END) AS recommend_percent';
        $summary = (clone $published)->selectRaw(implode(', ', $select))->first();

        $partnerComparison = DB::table('place_reviews as review')
            ->join('users as author', 'author.id', '=', 'review.author_user_id')
            ->where('review.place_id', $place->id)
            ->where('review.status', 'published')
            ->groupBy('review.author_user_id', 'author.name')
            ->orderBy('author.name')
            ->get([
                'review.author_user_id',
                'author.name',
                DB::raw('COUNT(*) AS visits'),
                DB::raw('AVG(review.overall_rating) AS overall_average'),
                DB::raw('AVG(review.food_rating) AS food_average'),
                DB::raw('AVG(review.service_rating) AS service_average'),
                DB::raw('AVG(review.value_rating) AS value_average'),
            ])
            ->map(fn ($row) => [
                'user_id' => (int) $row->author_user_id,
                'name' => $row->name,
                'visits' => (int) $row->visits,
                'overall_average' => $this->number($row->overall_average),
                'food_average' => $this->number($row->food_average),
                'service_average' => $this->number($row->service_average),
                'value_average' => $this->number($row->value_average),
            ])->values();

        $topItems = DB::table('place_review_items as item')
            ->join('place_reviews as review', 'review.id', '=', 'item.place_review_id')
            ->where('review.place_id', $place->id)
            ->where('review.status', 'published')
            ->whereNotNull('item.overall_rating')
            ->groupBy('item.category', 'item.name')
            ->orderByDesc(DB::raw('AVG(item.overall_rating)'))
            ->limit(12)
            ->get([
                'item.category', 'item.name',
                DB::raw('COUNT(*) AS ratings_count'),
                DB::raw('AVG(item.overall_rating) AS average'),
                DB::raw('AVG(item.price) AS average_price'),
                DB::raw('SUM(CASE WHEN item.would_order_again = 1 THEN 1 ELSE 0 END) AS order_again_count'),
            ])->map(fn ($item) => [
                'category' => $item->category,
                'name' => $item->name,
                'ratings_count' => (int) $item->ratings_count,
                'average' => $this->number($item->average),
                'average_price' => $this->number($item->average_price, 2),
                'order_again_count' => (int) $item->order_again_count,
            ])->values();

        return response()->json([
            'summary' => [
                'review_count' => (int) ($summary?->review_count ?? 0),
                'reviewers_count' => (int) ($summary?->reviewers_count ?? 0),
                'criteria' => collect(self::CRITERIA)->map(fn ($label, $key) => [
                    'key' => $key,
                    'label' => $label,
                    'average' => $this->number($summary?->{$key . '_average'}),
                ])->values(),
                'return_percent' => $this->number($summary?->return_percent, 0),
                'recommend_percent' => $this->number($summary?->recommend_percent, 0),
            ],
            'partner_comparison' => $partnerComparison,
            'top_items' => $topItems,
            'reviews' => $reviews->map(fn (PlaceReview $review) => $this->payload($review, $request->user()->id))->values(),
            'plans' => DB::table('place_plans')->where('place_id', $place->id)->whereIn('state', ['planned', 'visited'])->orderByDesc('planned_for')->get(['uuid', 'state', 'planned_for', 'visited_on']),
            'review_album' => $place->review_album_id ? Album::whereKey($place->review_album_id)->first(['id', 'uuid', 'title']) : null,
            'gallery_space_id' => $space->id,
        ]);
    }

    public function store(Request $request, Place $place): JsonResponse
    {
        $space = $this->space($request, $place);
        $this->authorizeWrite($request);
        $data = $this->validated($request);
        $plan = $this->plan($place, $data['place_plan_uuid'] ?? null, $space->id);
        $this->ensurePublishable($data);

        $existing = $plan ? PlaceReview::where('place_plan_id', $plan->id)->where('author_user_id', $request->user()->id)->first() : null;
        $review = DB::transaction(function () use ($request, $place, $space, $data, $plan, $existing) {
            $review = $existing ?? new PlaceReview();
            $review->fill($this->reviewData($data) + [
                'gallery_space_id' => $space->id,
                'place_id' => $place->id,
                'place_plan_id' => $plan?->id,
                'author_user_id' => $request->user()->id,
                'visited_at' => $data['visited_at'] ?? ($plan?->visited_on ? $plan->visited_on . ' 12:00:00' : ($plan?->planned_for ? $plan->planned_for . ' 12:00:00' : now())),
            ]);
            $review->save();
            $this->syncItems($review, $data['items'] ?? []);
            $this->syncMedia($review, $place, $space->id, $data['media_uuids'] ?? []);
            if ($plan && ($data['status'] ?? 'published') === 'published') {
                $this->markPlanVisited($plan, $data['visited_at'] ?? null);
            }
            $this->refreshPlaceRating($place);
            return $review->fresh(['author:id,name', 'items', 'media.variants']);
        });
        AuditLog::record($existing ? 'place.review.update' : 'place.review.create', $review, ['place_id' => $place->id, 'status' => $review->status]);

        return response()->json($this->payload($review, $request->user()->id), $existing ? 200 : 201);
    }

    public function update(Request $request, Place $place, string $uuid): JsonResponse
    {
        $space = $this->space($request, $place);
        $this->authorizeWrite($request);
        $review = PlaceReview::where('uuid', $uuid)->where('place_id', $place->id)->where('gallery_space_id', $space->id)->firstOrFail();
        abort_unless((int) $review->author_user_id === (int) $request->user()->id, 403, 'Každý upravuje pouze svůj vlastní pohled na návštěvu.');
        $data = $this->validated($request);
        $this->ensurePublishable($data);
        $plan = $this->plan($place, $data['place_plan_uuid'] ?? null, $space->id);

        DB::transaction(function () use ($review, $place, $space, $data, $plan): void {
            $review->update($this->reviewData($data) + ['place_plan_id' => $plan?->id, 'visited_at' => $data['visited_at'] ?? $review->visited_at]);
            $this->syncItems($review, $data['items'] ?? []);
            $this->syncMedia($review, $place, $space->id, $data['media_uuids'] ?? []);
            if ($plan && ($data['status'] ?? $review->status) === 'published') $this->markPlanVisited($plan, $data['visited_at'] ?? null);
            $this->refreshPlaceRating($place);
        });
        AuditLog::record('place.review.update', $review, ['place_id' => $place->id, 'status' => $review->status]);

        return response()->json($this->payload($review->fresh(['author:id,name', 'items', 'media.variants']), $request->user()->id));
    }

    public function destroy(Request $request, Place $place, string $uuid): JsonResponse
    {
        $this->space($request, $place);
        $this->authorizeWrite($request);
        $review = PlaceReview::where('uuid', $uuid)->where('place_id', $place->id)->firstOrFail();
        abort_unless((int) $review->author_user_id === (int) $request->user()->id, 403);
        AuditLog::record('place.review.delete', $review, ['place_id' => $place->id]);
        $review->delete();
        $this->refreshPlaceRating($place);
        return response()->json(['status' => 'deleted']);
    }

    public function ensureAlbum(Request $request, Place $place): JsonResponse
    {
        $space = $this->space($request, $place);
        $this->authorizeWrite($request);
        $created = false;
        $album = $place->review_album_id ? Album::whereKey($place->review_album_id)->where('gallery_space_id', $space->id)->first() : null;
        if (! $album) {
            $album = DB::transaction(function () use ($request, $place, $space, &$created) {
                $locked = Place::whereKey($place->id)->lockForUpdate()->firstOrFail();
                if ($locked->review_album_id && ($existing = Album::find($locked->review_album_id))) return $existing;
                $created = true;
                $album = Album::create([
                    'gallery_space_id' => $space->id,
                    'title' => 'Ochutnávky · ' . $place->name,
                    'slug' => Str::slug('ochutnavky-' . $place->name . '-' . $place->id),
                    'description' => 'Fotografie jídel, nápojů, nabídky a společných návštěv podniku ' . $place->name . '.',
                    'visibility' => 'shared',
                    'icon' => '🍽️',
                    'color' => '#f97316',
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                    'sync_status' => 'pending',
                ]);
                $album->rebuildPaths();
                $locked->update(['review_album_id' => $album->id]);
                DB::table('album_place')->insertOrIgnore(['album_id' => $album->id, 'place_id' => $place->id, 'is_primary' => true, 'created_at' => now()]);
                $permissions = DB::table('gallery_space_user')->where('gallery_space_id', $space->id)->pluck('user_id')->map(fn ($userId) => [
                    'album_id' => $album->id, 'user_id' => $userId, 'role' => 'editor', 'inherited' => false, 'created_at' => now(), 'updated_at' => now(),
                ])->all();
                if ($permissions) DB::table('album_user_permissions')->upsert($permissions, ['album_id', 'user_id'], ['role', 'updated_at']);
                return $album;
            });
            if ($created) CreateDriveFolderJob::dispatch($album);
        }

        return response()->json(['album' => $album->only(['id', 'uuid', 'title']), 'created' => $created], $created ? 201 : 200);
    }

    private function validated(Request $request): array
    {
        $ratings = 'nullable|numeric|between:1,5';
        return $request->validate([
            'status' => 'required|in:draft,published',
            'place_plan_uuid' => 'nullable|uuid',
            'visited_at' => 'nullable|date',
            'visit_context' => 'nullable|in:breakfast,lunch,dinner,coffee,dessert,drinks,takeaway,delivery,other',
            'party_size' => 'nullable|integer|between:1,30',
            'overall_rating' => $ratings,
            'service_rating' => $ratings,
            'staff_friendliness_rating' => $ratings,
            'food_rating' => $ratings,
            'food_quality_rating' => $ratings,
            'drink_rating' => $ratings,
            'speed_rating' => $ratings,
            'menu_rating' => $ratings,
            'atmosphere_rating' => $ratings,
            'cleanliness_rating' => $ratings,
            'value_rating' => $ratings,
            'wait_minutes' => 'nullable|integer|between:0,1440',
            'total_amount' => 'nullable|numeric|between:0,9999999999.99',
            'currency' => 'required|string|size:3',
            'would_return' => 'nullable|boolean',
            'recommends' => 'nullable|boolean',
            'positives' => 'nullable|string|max:5000',
            'improvements' => 'nullable|string|max:5000',
            'notes' => 'nullable|string|max:10000',
            'next_time_note' => 'nullable|string|max:5000',
            'items' => 'nullable|array|max:50',
            'items.*.category' => 'required|in:food,drink,dessert,coffee,menu,other',
            'items.*.name' => 'required|string|max:160',
            'items.*.quantity' => 'nullable|numeric|between:0.01,9999',
            'items.*.overall_rating' => $ratings,
            'items.*.quality_rating' => $ratings,
            'items.*.presentation_rating' => $ratings,
            'items.*.portion_rating' => $ratings,
            'items.*.value_rating' => $ratings,
            'items.*.price' => 'nullable|numeric|between:0,9999999999.99',
            'items.*.would_order_again' => 'nullable|boolean',
            'items.*.note' => 'nullable|string|max:3000',
            'media_uuids' => 'nullable|array|max:30',
            'media_uuids.*' => 'uuid|distinct',
        ]);
    }

    private function reviewData(array $data): array
    {
        return collect($data)->only([
            'status', 'visited_at', 'visit_context', 'party_size', 'overall_rating', 'service_rating',
            'staff_friendliness_rating', 'food_rating', 'food_quality_rating', 'drink_rating', 'speed_rating',
            'menu_rating', 'atmosphere_rating', 'cleanliness_rating', 'value_rating', 'wait_minutes',
            'total_amount', 'currency', 'would_return', 'recommends', 'positives', 'improvements',
            'notes', 'next_time_note',
        ])->all();
    }

    private function syncItems(PlaceReview $review, array $items): void
    {
        $review->items()->delete();
        foreach ($items as $sortOrder => $item) {
            $review->items()->create(collect($item)->only([
                'category', 'name', 'quantity', 'overall_rating', 'quality_rating', 'presentation_rating',
                'portion_rating', 'value_rating', 'price', 'would_order_again', 'note',
            ])->all() + ['currency' => $review->currency, 'sort_order' => $sortOrder]);
        }
    }

    private function syncMedia(PlaceReview $review, Place $place, int $spaceId, array $uuids): void
    {
        $uuids = array_values(array_unique($uuids));
        $media = MediaItem::where('gallery_space_id', $spaceId)->whereNull('trashed_at')->whereIn('uuid', $uuids)->get(['id', 'uuid']);
        if ($media->count() !== count($uuids)) throw ValidationException::withMessages(['media_uuids' => 'Některá fotografie není dostupná v této společné galerii.']);
        $sync = $media->values()->mapWithKeys(fn ($item, $index) => [$item->id => ['subject' => 'overall', 'sort_order' => $index, 'created_at' => now()]])->all();
        $review->media()->sync($sync);
        foreach ($media as $item) DB::table('media_place')->insertOrIgnore(['media_item_id' => $item->id, 'place_id' => $place->id, 'is_primary' => false, 'created_at' => now()]);
    }

    private function refreshPlaceRating(Place $place): void
    {
        $average = PlaceReview::where('place_id', $place->id)->where('status', 'published')->avg('overall_rating');
        $place->update(['personal_rating' => $average !== null ? max(1, min(5, (int) round($average))) : null]);
    }

    private function payload(PlaceReview $review, int $viewerId): array
    {
        return [
            'uuid' => $review->uuid,
            'status' => $review->status,
            'is_mine' => (int) $review->author_user_id === $viewerId,
            'author' => $review->author?->only(['id', 'name']),
            'place_plan_id' => $review->place_plan_id,
            'visited_at' => $review->visited_at?->toIso8601String(),
            'visit_context' => $review->visit_context,
            'party_size' => $review->party_size,
            'ratings' => collect(array_keys(self::CRITERIA))->mapWithKeys(fn ($key) => [$key => $review->{$key . '_rating'}])->all(),
            'wait_minutes' => $review->wait_minutes,
            'total_amount' => $review->total_amount,
            'currency' => $review->currency,
            'would_return' => $review->would_return,
            'recommends' => $review->recommends,
            'positives' => $review->positives,
            'improvements' => $review->improvements,
            'notes' => $review->notes,
            'next_time_note' => $review->next_time_note,
            'items' => $review->items->map(fn ($item) => $item->only(['uuid', 'category', 'name', 'quantity', 'overall_rating', 'quality_rating', 'presentation_rating', 'portion_rating', 'value_rating', 'price', 'currency', 'would_order_again', 'note']))->values(),
            'media' => $review->media->map(fn (MediaItem $media) => ['uuid' => $media->uuid, 'title' => $media->display_title ?: $media->original_filename, 'thumbnail_url' => $media->thumbnail_url, 'subject' => $media->pivot->subject, 'caption' => $media->pivot->caption])->values(),
            'created_at' => $review->created_at?->toIso8601String(),
            'updated_at' => $review->updated_at?->toIso8601String(),
        ];
    }

    private function plan(Place $place, ?string $uuid, int $spaceId): ?object
    {
        if (! $uuid) return null;
        return DB::table('place_plans')->where('uuid', $uuid)->where('place_id', $place->id)->where('gallery_space_id', $spaceId)->firstOrFail();
    }

    private function ensurePublishable(array $data): void
    {
        if (($data['status'] ?? 'published') === 'published' && empty($data['overall_rating'])) {
            throw ValidationException::withMessages(['overall_rating' => 'Pro zveřejnění vyberte celkové hodnocení podniku.']);
        }
    }

    private function markPlanVisited(object $plan, ?string $visitedAt): void
    {
        $visitedOn = Carbon::parse($visitedAt ?? $plan->visited_on ?? $plan->planned_for ?? now())->toDateString();
        DB::table('place_plans')->where('id', $plan->id)->update(['state' => 'visited', 'visited_on' => $visitedOn, 'updated_at' => now()]);
        if ($plan->calendar_event_id) {
            DB::table('calendar_events')->where('id', $plan->calendar_event_id)->where('starts_at', '<=', now())->whereNotIn('status', ['cancelled'])->update(['status' => 'completed', 'updated_at' => now()]);
        }
    }

    private function space(Request $request, Place $place): object
    {
        abort_unless(Schema::hasTable('place_reviews'), 503, 'Pro hodnocení podniků dokončete migrace aplikace.');
        $space = $request->user()->gallerySpaces()->firstOrFail();
        abort_unless((int) $place->gallery_space_id === (int) $space->id, 404);
        return $space;
    }

    private function authorizeWrite(Request $request): void
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze hodnocení měnit.');
    }

    private function number(mixed $value, int $precision = 1): ?float
    {
        return $value === null ? null : round((float) $value, $precision);
    }
}
