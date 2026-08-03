<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Person;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PersonController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->firstOrFail();
        $people = Person::where('gallery_space_id', $space->id)->withCount('media')->orderBy('name')->get()
            ->map(fn (Person $person) => $this->personPayload($person));
        return response()->json($people);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'nickname' => 'nullable|string|max:100', 'birth_date' => 'nullable|date', 'description' => 'nullable|string|max:5000']);
        $space = $request->user()->gallerySpaces()->firstOrFail();
        $person = Person::create($data + ['gallery_space_id' => $space->id, 'created_by' => $request->user()->id]);
        return response()->json($this->personPayload($person), 201);
    }

    public function show(Request $request, Person $person): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->firstOrFail();
        $this->authorizePerson($person, $space->id);
        $media = $person->media()->where('gallery_space_id', $space->id)->whereNull('trashed_at')->with('variants')->latest('taken_at')->limit(36)->get()
            ->map(fn ($item) => ['id' => $item->id, 'uuid' => $item->uuid, 'thumbnail_url' => $item->thumbnail_url, 'taken_at' => optional($item->taken_at)->toIso8601String()]);
        $albums = DB::table('album_person as link')->join('albums as album', 'album.id', '=', 'link.album_id')->where('link.person_id', $person->id)->where('album.gallery_space_id', $space->id)->whereNull('album.deleted_at')->orderByDesc('album.created_at')->limit(12)->get(['album.id', 'album.uuid', 'album.title']);
        $giftQuery = DB::table('gift_ideas')->where('gallery_space_id', $space->id)->where('person_id', $person->id)->whereNotIn('status', ['archived']);
        if (Schema::hasColumn('gift_ideas', 'visibility') && Schema::hasColumn('gift_ideas', 'private_to_user_id')) {
            $giftQuery->where(fn ($visible) => $visible->where('visibility', 'shared')->orWhere('private_to_user_id', $request->user()->id));
        }
        $gifts = Schema::hasTable('gift_ideas')
            ? $giftQuery->orderByRaw('due_date IS NULL, due_date')->limit(12)->get(['uuid', 'title', 'occasion', 'due_date', 'budget', 'currency', 'status'])
            : collect();
        return response()->json(['person' => $this->personPayload($person), 'media' => $media, 'albums' => $albums, 'gifts' => $gifts]);
    }

    public function update(Request $request, Person $person): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->firstOrFail();
        $this->authorizePerson($person, $space->id);
        $data = $request->validate(['name' => 'sometimes|string|max:100', 'nickname' => 'nullable|string|max:100', 'description' => 'nullable|string|max:5000', 'birth_date' => 'nullable|date', 'is_favorite' => 'nullable|boolean', 'is_hidden' => 'nullable|boolean']);
        $person->update($data);
        return response()->json($this->personPayload($person->fresh()));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->firstOrFail();
        $person = Person::findOrFail($id);
        $this->authorizePerson($person, $space->id);
        $person->delete();
        return response()->json(['status' => 'deleted']);
    }

    /** Exposes one shared note and only the current member's private note. */
    public function notes(Request $request, Person $person): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->firstOrFail();
        $this->authorizePerson($person, $space->id);
        abort_unless(Schema::hasTable('person_notes'), 503, 'Poznámky budou dostupné po dokončení aktualizace databáze.');
        $shared = DB::table('person_notes')->where('person_id', $person->id)->where('scope_key', 'shared')->first();
        $mine = DB::table('person_notes')->where('person_id', $person->id)->where('scope_key', 'personal:'.$request->user()->id)->first();
        return response()->json(['shared' => $shared, 'mine' => $mine]);
    }

    public function saveNotes(Request $request, Person $person): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->firstOrFail();
        $this->authorizePerson($person, $space->id);
        abort_unless(Schema::hasTable('person_notes'), 503, 'Poznámky budou dostupné po dokončení aktualizace databáze.');
        $data = $request->validate(['visibility' => 'required|in:shared,personal', 'content' => 'nullable|string|max:10000']);
        $user = $request->user(); $content = trim((string) ($data['content'] ?? ''));
        $scopeKey = $data['visibility'] === 'shared' ? 'shared' : 'personal:'.$user->id;
        $query = DB::table('person_notes')->where('person_id', $person->id)->where('scope_key', $scopeKey);
        if ($content === '') { $query->delete(); return response()->json(['note' => null]); }
        $now = now();
        DB::table('person_notes')->upsert([['person_id' => $person->id, 'gallery_space_id' => $space->id, 'user_id' => $data['visibility'] === 'personal' ? $user->id : null, 'visibility' => $data['visibility'], 'scope_key' => $scopeKey, 'content' => $content, 'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => $now, 'updated_at' => $now]], ['person_id', 'scope_key'], ['content', 'updated_by', 'updated_at']);
        return response()->json(['note' => $query->first()]);
    }
    private function authorizePerson(Person $person, int $spaceId): void
    {
        abort_unless((int) $person->gallery_space_id === $spaceId, 403);
    }

    private function personPayload(Person $person): array
    {
        $person->loadMissing('cover.variants');
        return ['id' => $person->id, 'name' => $person->name, 'nickname' => $person->nickname, 'description' => $person->description, 'birth_date' => optional($person->birth_date)->toDateString(), 'is_favorite' => (bool) $person->is_favorite, 'is_hidden' => (bool) $person->is_hidden, 'media_count' => (int) ($person->media_count ?? $person->media()->count()), 'latest_thumb' => $person->cover?->thumbnail_url];
    }
}
