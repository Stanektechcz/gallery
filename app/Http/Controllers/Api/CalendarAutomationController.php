<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Planning\GiftIdeaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CalendarAutomationController extends Controller
{
    public function gifts(Request $request): JsonResponse
    {
        $query = DB::table('gift_ideas as gift')->whereIn('gift.gallery_space_id', $this->spaceIds($request))->whereNotIn('gift.status', ['archived'])->orderByRaw('gift.due_date is null, gift.due_date');
        $this->restrictGiftVisibility($query, $request->user()->id);
        if (Schema::hasColumn('gift_ideas', 'assigned_to')) $query->leftJoin('users as assignee', 'assignee.id', '=', 'gift.assigned_to');
        $columns = Schema::hasColumn('gift_ideas', 'assigned_to') ? ['gift.*', 'assignee.name as assignee_name'] : ['gift.*'];
        return response()->json($query->get($columns)->map(fn ($gift) => $this->giftPayload($gift, $request->user()->id))->values());
    }

    public function storeGift(Request $request, GiftIdeaService $gifts): JsonResponse
    {
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'person_id' => 'nullable|integer', 'assigned_to' => 'nullable|integer', 'title' => 'required|string|max:255', 'occasion' => 'nullable|string|max:80', 'due_date' => 'nullable|date', 'budget' => 'nullable|numeric|min:0|max:999999999', 'currency' => 'nullable|string|size:3', 'source_url' => 'nullable|url|max:2048', 'reminder_days' => 'nullable|array|max:10', 'reminder_days.*' => 'integer|min:0|max:365', 'is_private' => 'nullable|boolean']);
        abort_unless($request->user()->gallerySpaces()->whereKey($data['gallery_space_id'])->exists(), 404);
        if (!empty($data['source_url']) && !Str::startsWith($data['source_url'], 'https://')) abort(422, 'Odkazy na dárky musí používat HTTPS.');
        if (!empty($data['person_id'])) DB::table('people')->where('id', $data['person_id'])->where('gallery_space_id', $data['gallery_space_id'])->firstOrFail();
        if (!empty($data['assigned_to'])) abort_unless(DB::table('gallery_space_user')->where('gallery_space_id', $data['gallery_space_id'])->where('user_id', $data['assigned_to'])->exists(), 422, 'Dárek lze přiřadit pouze členovi společného prostoru.');
        $gift = $gifts->create((int) $data['gallery_space_id'], $request->user()->id, $data, 'manual');
        return response()->json($this->giftPayload($gift, $request->user()->id), 201);
    }

    public function updateGift(Request $request, string $uuid, GiftIdeaService $gifts): JsonResponse
    {
        $query = DB::table('gift_ideas as gift')->where('gift.uuid', $uuid)->whereIn('gift.gallery_space_id', $this->spaceIds($request));
        $this->restrictGiftVisibility($query, $request->user()->id);
        $gift = $query->firstOrFail();
        $data = $request->validate(['status' => 'nullable|in:idea,planned,purchased,archived', 'stage' => 'nullable|in:idea,planned,purchased,wrapped,given', 'title' => 'sometimes|string|max:255', 'due_date' => 'nullable|date', 'budget' => 'nullable|numeric|min:0|max:999999999', 'assigned_to' => 'sometimes|nullable|integer', 'is_private' => 'sometimes|boolean']);
        if (array_key_exists('assigned_to', $data) && $data['assigned_to'] !== null) abort_unless(DB::table('gallery_space_user')->where('gallery_space_id', $gift->gallery_space_id)->where('user_id', $data['assigned_to'])->exists(), 422, 'Dárek lze přiřadit pouze členovi společného prostoru.');
        if (!Schema::hasColumn('gift_ideas', 'assigned_to')) unset($data['assigned_to']);
        if (array_key_exists('is_private', $data)) {
            abort_unless((int) $gift->created_by === (int) $request->user()->id, 403, 'Soukromí dárku může změnit pouze jeho autor.');
            $isPrivate = (bool) $data['is_private']; unset($data['is_private']);
            if (Schema::hasColumn('gift_ideas', 'visibility') && Schema::hasColumn('gift_ideas', 'private_to_user_id')) {
                $data['visibility'] = $isPrivate ? 'private' : 'shared';
                $data['private_to_user_id'] = $isPrivate ? $request->user()->id : null;
            }
        }
        $stage = $data['stage'] ?? null;
        unset($data['stage']);
        if ($data) {
            DB::table('gift_ideas')->where('id', $gift->id)->update($data + ['updated_at' => now()]);
            $gift = DB::table('gift_ideas')->find($gift->id);
        }
        $updated = $stage ? $gifts->transition($gift, $request->user()->id, $stage, 'manual') : $gift;
        return response()->json($this->giftPayload($updated, $request->user()->id));
    }

    private function giftPayload(object $gift, ?int $viewerId = null): object
    {
        $gift->lifecycle = json_decode($gift->lifecycle ?? '[]', true) ?: [['stage' => $gift->status ?? 'idea', 'at' => $gift->created_at ?? null]];
        $gift->is_private = ($gift->visibility ?? 'shared') === 'private';
        $gift->can_toggle_privacy = $viewerId !== null && (int) $gift->created_by === $viewerId;
        return $gift;
    }

    /** Applies the same privacy policy to every gift endpoint. */
    private function restrictGiftVisibility($query, int $userId): void
    {
        if (!Schema::hasColumn('gift_ideas', 'visibility') || !Schema::hasColumn('gift_ideas', 'private_to_user_id')) return;
        $query->where(fn ($visible) => $visible->where('gift.visibility', 'shared')->orWhere('gift.private_to_user_id', $userId));
    }

    public function dayNote(Request $request): JsonResponse
    {
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'date' => 'nullable|date']);
        $date = $data['date'] ?? now()->toDateString();
        $note = DB::table('shared_day_notes')->where('gallery_space_id', $data['gallery_space_id'])->where('note_date', $date)->whereIn('gallery_space_id', $this->spaceIds($request))->first();
        return response()->json(['date' => $date, 'content' => $note ? Crypt::decryptString($note->encrypted_content) : '']);
    }

    public function updateDayNote(Request $request): JsonResponse
    {
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'date' => 'nullable|date', 'content' => 'nullable|string|max:10000']);
        abort_unless($request->user()->gallerySpaces()->whereKey($data['gallery_space_id'])->exists(), 404);
        $date = $data['date'] ?? now()->toDateString();
        if (blank($data['content'] ?? null)) { DB::table('shared_day_notes')->where('gallery_space_id', $data['gallery_space_id'])->where('note_date', $date)->delete(); return response()->json(['date' => $date, 'content' => '']); }
        DB::table('shared_day_notes')->updateOrInsert(['gallery_space_id' => $data['gallery_space_id'], 'note_date' => $date], ['created_by' => $request->user()->id, 'encrypted_content' => Crypt::encryptString($data['content']), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['date' => $date, 'content' => $data['content']]);
    }

    private function spaceIds(Request $request): array { return $request->user()->gallerySpaces()->pluck('gallery_spaces.id')->all(); }
}