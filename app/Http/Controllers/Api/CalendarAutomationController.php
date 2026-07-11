<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CalendarAutomationController extends Controller
{
    public function gifts(Request $request): JsonResponse
    {
        return response()->json(DB::table('gift_ideas')->whereIn('gallery_space_id', $this->spaceIds($request))->whereNotIn('status', ['purchased', 'archived'])->orderByRaw('due_date is null, due_date')->get());
    }

    public function storeGift(Request $request): JsonResponse
    {
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'person_id' => 'nullable|integer', 'title' => 'required|string|max:255', 'occasion' => 'nullable|string|max:80', 'due_date' => 'nullable|date', 'budget' => 'nullable|numeric|min:0|max:999999999', 'currency' => 'nullable|string|size:3', 'source_url' => 'nullable|url|max:2048', 'reminder_days' => 'nullable|array|max:10', 'reminder_days.*' => 'integer|min:0|max:365']);
        abort_unless($request->user()->gallerySpaces()->whereKey($data['gallery_space_id'])->exists(), 404);
        if (!empty($data['source_url']) && !Str::startsWith($data['source_url'], 'https://')) abort(422, 'Odkazy na dárky musí používat HTTPS.');
        if (!empty($data['person_id'])) DB::table('people')->where('id', $data['person_id'])->where('gallery_space_id', $data['gallery_space_id'])->firstOrFail();
        $id = DB::table('gift_ideas')->insertGetId(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $data['gallery_space_id'], 'created_by' => $request->user()->id, 'person_id' => $data['person_id'] ?? null, 'title' => $data['title'], 'occasion' => $data['occasion'] ?? null, 'due_date' => $data['due_date'] ?? null, 'budget' => $data['budget'] ?? null, 'currency' => strtoupper($data['currency'] ?? 'CZK'), 'source_url' => $data['source_url'] ?? null, 'status' => 'idea', 'reminder_days' => json_encode($data['reminder_days'] ?? [30, 7, 1]), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('gift_ideas')->find($id), 201);
    }

    public function updateGift(Request $request, string $uuid): JsonResponse
    {
        $gift = DB::table('gift_ideas')->where('uuid', $uuid)->whereIn('gallery_space_id', $this->spaceIds($request))->firstOrFail();
        $data = $request->validate(['status' => 'nullable|in:idea,planned,purchased,archived', 'title' => 'sometimes|string|max:255', 'due_date' => 'nullable|date', 'budget' => 'nullable|numeric|min:0|max:999999999']);
        DB::table('gift_ideas')->where('id', $gift->id)->update($data + ['updated_at' => now()]);
        return response()->json(DB::table('gift_ideas')->find($gift->id));
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
